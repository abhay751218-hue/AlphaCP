<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use Throwable;

/**
 * BIND9 — real authoritative zones for the domains AlphaCP hosts.
 *
 * Until now the panel kept DNS records as JSON ("no BIND rewrite"). This class
 * is the step that makes them real, with the same rules as the rest of the
 * agent:
 *  - **nothing is written before `named-checkzone` accepted it** — the zone is
 *    built in a temp file, validated, and only then renamed into place. A bad
 *    record can therefore never take the live zone down.
 *  - **argv only**: `named-checkconf`, `named-checkzone`, `rndc`, `dig` each get
 *    their own argv; no shell string is ever built.
 *  - **idempotent**: `setup()` can run on every update — it only creates what is
 *    missing and only touches named.conf once (managed markers, backup kept).
 *  - **honest**: every write ends with a real `dig @127.0.0.1 <zone> SOA` and the
 *    answer is reported back, so "written" means "the server really answers it".
 */
final class BindServer
{
    public const CHECKCONF = '/usr/sbin/named-checkconf';
    public const CHECKZONE = '/usr/sbin/named-checkzone';
    public const RNDC = '/usr/sbin/rndc';
    public const DIG = '/usr/bin/dig';

    // Real defaults. Every path is env-overridable (ACP_BIND_*) so the whole
    // class can be exercised inside a temp root — tests never touch /etc/bind.
    private const DEFAULT_CONF = '/etc/bind/named.conf';
    private const DEFAULT_OPTIONS = '/etc/bind/named.conf.options';
    private const DEFAULT_ZONES = '/etc/bind/named.conf.alphacp';
    private const DEFAULT_ZONE_DIR = '/etc/bind/zones';

    public const MANAGED_BEGIN = '// >>> AlphaCP managed (dns.bind) — haath se edit mat karo';
    public const MANAGED_END = '// <<< AlphaCP managed (dns.bind)';

    public const DEFAULT_TTL = 300;
    public const DEFAULT_MX_PREF = 10;
    public const MAX_ZONES = 500;
    public const CMD_TIMEOUT = 60;

    /** @var list<string> temp files removed in cleanup() */
    private array $tempFiles = [];

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
    ) {
    }

    // ---------------------------------------------------------------- status --

    /** @return array<string, mixed> */
    public function status(): array
    {
        $out = [
            'installed'   => $this->installed(),
            'checkconf'   => null,
            'rndc'        => null,
            'zones'       => count($this->zoneFiles()),
            'zone_dir'    => $this->zoneDir(),
            'include'     => $this->zonesFile(),
            'listen'      => [],
        ];
        if (!$out['installed']) {
            return $out + ['error' => 'bind9 install nahi hai (updater install karta hai)'];
        }

        $conf = $this->cmd->run([self::checkconfBin(), $this->confFile()], self::CMD_TIMEOUT);
        $out['checkconf'] = $conf->ok() ? 'ok' : trim($conf->stderr);

        $rndc = $this->cmd->run([self::rndcBin(), 'status'], self::CMD_TIMEOUT);
        $out['rndc'] = $rndc->ok() ? self::firstLine($rndc->stdout) : trim($rndc->stderr);

        foreach (preg_split('/\R/', (string) $rndc->stdout) ?: [] as $line) {
            if (preg_match('/listening on.*port 53\s*\{([^}]*)\}/', $line, $m) === 1) {
                $out['listen'] = array_values(array_filter(array_map('trim', preg_split('/\s*;\s*/', $m[1]) ?: [])));
            }
        }

        return $out;
    }

    public function installed(): bool
    {
        return self::have('ACP_BIND_CHECKCONF', self::CHECKCONF)
            && self::have('ACP_BIND_CHECKZONE', self::CHECKZONE);
    }

    // ----------------------------------------------------------------- setup --

    /**
     * Idempotent provisioning: zone dir, managed options (listen on loopback +
     * the server's own IPv4 so systemd-resolved's 127.0.0.53 stays untouched),
     * one include line in named.conf and a running `named`.
     *
     * @return array<string, mixed>
     */
    public function setup(): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException(
                'bind9 (named-checkconf/named-checkzone) nahi mila — updater install karta hai '
                . '(`apt-get install -y bind9 bind9-utils dnsutils`), uske baad dobara chalao'
            );
        }

        $dir = $this->zoneDir();
        $this->ensureDir($dir, 0750);

        $ip = $this->primaryIpv4();
        $listen = $ip === null ? ['127.0.0.1'] : ['127.0.0.1', $ip];
        $options = $this->renderOptions($listen);
        if (!is_file($this->optionsBackupFile()) && is_file($this->optionsFile())) {
            @copy($this->optionsFile(), $this->optionsBackupFile());   // pehli baar: asli file bachao
        }
        $this->writeConfig($this->optionsFile(), $options, 0644);

        $zones = $this->renderZonesFile($this->zoneFiles());
        $this->writeConfig($this->zonesFile(), $zones, 0644);

        if (!$this->ensureInclude()) {
            throw new TaskRejectedException($this->confFile() . ' me include line nahi likhi ja saki');
        }

        $conf = $this->cmd->run([self::checkconfBin(), $this->confFile()], self::CMD_TIMEOUT);
        if (!$conf->ok()) {
            $this->restoreOptions();
            throw new TaskRejectedException('named-checkconf fail (purani config wapas): ' . self::cleanError($conf));
        }

        $this->cmd->run(['/bin/systemctl', 'enable', 'named'], self::CMD_TIMEOUT);
        $restart = $this->cmd->run(['/bin/systemctl', 'restart', 'named'], self::CMD_TIMEOUT);
        if (!$restart->ok()) {
            $this->cmd->run(['/bin/systemctl', 'restart', 'bind9'], self::CMD_TIMEOUT);
        }

        $status = $this->status();

        return [
            'ok'          => true,
            'listen'      => $listen,
            'zone_dir'    => $dir,
            'include'     => $this->zonesFile(),
            'checkconf'   => $status['checkconf'] ?? null,
            'rndc'        => $status['rndc'] ?? null,
            'zones'       => $status['zones'] ?? 0,
        ];
    }

    // ------------------------------------------------------------------ zone --

    /**
     * Write one zone: build → `named-checkzone` → atomic rename → reload → dig.
     *
     * @param list<array{domain:string,name:string,type:string,value:string}> $records
     * @return array<string, mixed>
     */
    public function writeZone(string $domain, array $records, ?array $nameservers = null, int $ttl = self::DEFAULT_TTL): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException('bind9 nahi mila — pehle dns.bind setup chalao');
        }
        $domain = Dns::normalizeDomain($domain);
        $rows = Dns::sanitize($records);
        $ns = $nameservers ?? $this->configuredNameservers($domain);
        $ip = $this->primaryIpv4() ?? '127.0.0.1';

        $this->ensureDir($this->zoneDir(), 0750);
        $serial = $this->nextSerial($domain);
        $body = self::renderZone($domain, $rows, $ns, $ip, $serial, $ttl);
        $rendered = 0;
        foreach ($rows as $row) {
            $rd = (string) ($row['domain'] ?? '');
            if ($rd === $domain || str_ends_with($rd, '.' . $domain)) {
                $rendered++;
            }
        }

        $tmp = $this->zoneDir() . '/.db.' . $domain . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $this->tempFiles[] = $tmp;
        if (@file_put_contents($tmp, $body) === false) {
            throw new TaskRejectedException("zone temp file {$tmp} likhi nahi ja saki");
        }
        @chmod($tmp, 0644);

        // GATE: named-checkzone ke bina ek bhi byte live zone me nahi jata
        $check = $this->cmd->run([self::checkzoneBin(), $domain, $tmp], self::CMD_TIMEOUT);
        if (!$check->ok()) {
            $this->cleanupTemp();
            throw new TaskRejectedException(
                "named-checkzone ne zone {$domain} reject kar diya: " . self::cleanError($check)
            );
        }

        $final = $this->zoneDir() . '/db.' . $domain;
        if (!@rename($tmp, $final)) {
            $this->cleanupTemp();
            throw new TaskRejectedException("zone file {$final} likhi nahi ja saki");
        }
        @chmod($final, 0644);

        // named.conf me zone clause (idempotent) + poora config check
        $this->writeConfig($this->zonesFile(), $this->renderZonesFile($this->zoneFiles()), 0644);
        if (!$this->ensureInclude()) {
            throw new TaskRejectedException($this->confFile() . ' me include line nahi likhi ja saki');
        }
        $conf = $this->cmd->run([self::checkconfBin(), $this->confFile()], self::CMD_TIMEOUT);
        if (!$conf->ok()) {
            throw new TaskRejectedException('named-checkconf fail: ' . self::cleanError($conf));
        }

        $reload = $this->cmd->run([self::rndcBin(), 'reload', $domain], self::CMD_TIMEOUT);
        $verified = $this->soaAnswer($domain);

        $this->log->info("bind zone {$domain} written ({$serial}) — dig: " . ($verified === '' ? 'koi jawab nahi' : $verified));

        return [
            'ok'       => true,
            'domain'   => $domain,
            'file'     => $final,
            'serial'   => $serial,
            'records'  => $rendered,
            'ns'       => $ns,
            'reload'   => $reload->ok() ? 'ok' : self::cleanError($reload),
            'dig_soa'  => $verified,
            'verified' => $verified !== '',
        ];
    }

    /** @return array<string, mixed> */
    public function removeZone(string $domain): array
    {
        $domain = Dns::normalizeDomain($domain);
        $file = $this->zoneDir() . '/db.' . $domain;
        $existed = is_file($file);
        if ($existed) {
            @unlink($file);
        }
        $this->writeConfig($this->zonesFile(), $this->renderZonesFile($this->zoneFiles()), 0644);
        $reload = $this->installed()
            ? $this->cmd->run([self::rndcBin(), 'reload', $domain], self::CMD_TIMEOUT)
            : null;

        return [
            'ok'      => true,
            'domain'  => $domain,
            'removed' => $existed,
            'reload'  => $reload === null ? 'skipped' : ($reload->ok() ? 'ok' : self::cleanError($reload)),
        ];
    }

    /**
     * What named really answers (not what we think we wrote).
     *
     * @return array<string, mixed>
     */
    public function verify(string $domain): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException('bind9 nahi mila');
        }
        $domain = Dns::normalizeDomain($domain);

        return [
            'domain' => $domain,
            'soa'    => $this->dig($domain, 'SOA'),
            'a'      => $this->dig($domain, 'A'),
            'ns'     => $this->dig($domain, 'NS'),
        ];
    }

    /** @return list<string> zone names that have a file */
    public function zoneFiles(): array
    {
        if (!is_dir($this->zoneDir())) {
            return [];
        }
        $out = [];
        foreach (glob($this->zoneDir() . '/db.*') ?: [] as $file) {
            $name = substr(basename($file), 3);
            if ($name !== '' && Dns::validDomain($name)) {
                $out[] = $name;
            }
        }
        sort($out);

        return array_slice($out, 0, self::MAX_ZONES);
    }

    /** @return list<string> */
    public function configuredNameservers(string $domain): array
    {
        $root = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');
        $file = $root . '/etc/dns/nameserver.json';
        if (is_file($file) && !is_link($file)) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $ns = [];
                foreach (['ns1', 'ns2'] as $key) {
                    $value = strtolower(trim((string) ($decoded[$key] ?? '')));
                    if ($value !== '' && Dns::validDomain($value)) {
                        $ns[] = rtrim($value, '.');
                    }
                }
                if ($ns !== []) {
                    return $ns;
                }
            }
        }

        return ['ns1.' . $domain, 'ns2.' . $domain];
    }

    // --------------------------------------------------------------- rendering --

    /**
     * @param list<array{domain:string,name:string,type:string,value:string}> $rows
     * @param list<string> $nameservers
     */
    public static function renderZone(
        string $domain,
        array $rows,
        array $nameservers,
        string $ipv4,
        int $serial,
        int $ttl = self::DEFAULT_TTL,
    ): string {
        $out = [];
        $out[] = '$TTL ' . $ttl;
        $out[] = sprintf(
            '@ IN SOA %s. hostmaster.%s. ( %d 3600 600 1209600 %d )',
            rtrim($nameservers[0] ?? ('ns1.' . $domain), '.'),
            $domain,
            $serial,
            max(60, $ttl),
        );
        foreach ($nameservers as $ns) {
            $ns = rtrim($ns, '.');
            $out[] = '@ IN NS ' . $ns . '.';
        }
        // NS ke liye glue A records: jo NS is domain ke andar hain, unka A dena hi padta hai
        foreach ($nameservers as $ns) {
            $ns = rtrim($ns, '.');
            if ($ns === $domain || str_ends_with($ns, '.' . $domain)) {
                $label = $ns === $domain ? '@' : substr($ns, 0, -(strlen($domain) + 1));
                $out[] = $label . ' IN A ' . $ipv4;
            }
        }
        foreach ($rows as $row) {
            // Sirf is zone ke rows: doosre domain ka record kabhi is zone me nahi
            // likha jata (galati se bhi cross-domain leak nahi).
            $rowDomain = (string) ($row['domain'] ?? '');
            $name = ($row['name'] ?? '') === '' ? '@' : (string) $row['name'];
            if ($rowDomain === $domain) {
                // asli zone record — jaise hai waise
            } elseif (str_ends_with($rowDomain, '.' . $domain)) {
                // sub.domain ka record parent zone me: label bana ke likho
                $sub = substr($rowDomain, 0, -(strlen($domain) + 1));
                $name = ($name === '' || $name === '@') ? $sub : $name . '.' . $sub;
            } else {
                continue;
            }
            $type = strtoupper((string) $row['type']);
            $value = trim((string) $row['value']);
            if ($type === 'TXT') {
                $out[] = $name . ' IN TXT ' . self::quote($value);
            } elseif ($type === 'MX') {
                $out[] = $name . ' IN MX ' . self::DEFAULT_MX_PREF . ' ' . rtrim($value, '.') . '.';
            } elseif ($type === 'CNAME') {
                $out[] = $name . ' IN CNAME ' . rtrim($value, '.') . '.';
            } else {
                $out[] = $name . ' IN A ' . $value;
            }
        }

        return implode("\n", $out) . "\n";
    }

    /** @param list<string> $listen */
    private function renderOptions(array $listen): string
    {
        $lines = [
            self::MANAGED_BEGIN,
            'options {',
            '    directory "/var/cache/bind";',
            '    listen-on { ' . implode(' ', array_map(static fn (string $ip): string => $ip . ';', $listen)) . ' };',
            '    listen-on-v6 { none; };',
            '    allow-query { any; };',
            '    allow-transfer { none; };',
            '    recursion no;',
            '    dnssec-validation no;',
            '};',
            self::MANAGED_END,
        ];

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $zones */
    private function renderZonesFile(array $zones): string
    {
        $lines = [
            self::MANAGED_BEGIN,
            '// AlphaCP ke account domains — zone files ' . $this->zoneDir() . '/db.<domain>',
        ];
        foreach ($zones as $zone) {
            $lines[] = sprintf(
                'zone "%s" { type master; file "%s/db.%s"; allow-update { none; }; allow-transfer { none; }; };',
                $zone,
                $this->zoneDir(),
                $zone,
            );
        }
        $lines[] = self::MANAGED_END;

        return implode("\n", $lines) . "\n";
    }

    // ----------------------------------------------------------------- helpers --

    private function nextSerial(string $domain): int
    {
        $today = (int) gmdate('Ymd') * 100;
        $file = $this->zoneDir() . '/db.' . $domain;
        if (!is_file($file)) {
            return $today + 1;
        }
        $raw = (string) @file_get_contents($file);
        if (preg_match('/SOA\s+\S+\s+\S+\s*\(\s*(\d{10})/', $raw, $m) === 1) {
            $old = (int) $m[1];

            return max($today + 1, $old + 1);
        }

        return $today + 1;
    }

    private function soaAnswer(string $domain): string
    {
        return $this->dig($domain, 'SOA');
    }

    private function dig(string $domain, string $type): string
    {
        $res = $this->cmd->run(
            [self::digBin(), '@127.0.0.1', '+short', '+tries=1', '+time=2', $domain, $type],
            self::CMD_TIMEOUT,
        );
        if (!$res->ok()) {
            return '';
        }

        return trim((string) $res->stdout) === '' ? '' : trim((string) $res->stdout);
    }

    private function primaryIpv4(): ?string
    {
        $res = $this->cmd->run(['/usr/bin/hostname', '-I'], 15);
        if (!$res->ok()) {
            return null;
        }
        foreach (preg_split('/\s+/', trim((string) $res->stdout)) ?: [] as $token) {
            if (filter_var($token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $token !== '127.0.0.1') {
                return $token;
            }
        }

        return null;
    }

    private function ensureInclude(): bool
    {
        $line = 'include "' . $this->zonesFile() . '";';
        $current = is_file($this->confFile()) ? (string) @file_get_contents($this->confFile()) : '';
        if ($current === '') {
            return false;
        }
        if (str_contains($current, $line)) {
            return true;
        }
        $next = rtrim($current, "\n") . "\n" . $line . "\n";

        return @file_put_contents($this->confFile(), $next) !== false;
    }

    private function writeConfig(string $file, string $contents, int $mode): void
    {
        if (is_link($file)) {
            throw new TaskRejectedException("{$file} ek symlink hai — refuse");
        }
        if (@file_put_contents($file, $contents) === false) {
            throw new TaskRejectedException("{$file} likhi nahi ja saki");
        }
        @chmod($file, $mode);
    }

    private function restoreOptions(): void
    {
        if (is_file($this->optionsBackupFile())) {
            @copy($this->optionsBackupFile(), $this->optionsFile());
        }
    }

    private function ensureDir(string $dir, int $mode): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (is_link($dir) || (!@mkdir($dir, $mode, true) && !is_dir($dir))) {
            throw new TaskRejectedException("{$dir} ban nahi saki");
        }
        @chmod($dir, $mode);
    }

    private function cleanupTemp(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    private static function quote(string $value): string
    {
        $safe = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"' . $safe . '"';
    }

    private static function firstLine(string $text): string
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];

        return trim((string) ($lines[0] ?? ''));
    }

    private static function cleanError(CommandResult $res): string
    {
        $msg = trim($res->stderr !== '' ? $res->stderr : $res->stdout);
        $msg = preg_replace('/[^\x20-\x7E]+/', ' ', $msg) ?? '';
        $msg = str_replace(['"', "'", '`'], '', $msg);
        $msg = preg_replace('/\s+/', ' ', $msg) ?? '';

        return substr($msg, 0, 300) === '' ? "exit {$res->exitCode}" : substr($msg, 0, 300);
    }

    private static function which(string $env, string $default): string
    {
        $override = trim((string) (getenv($env) ?: ''));
        if ($override === '') {
            return $default;
        }
        if (!str_starts_with($override, '/') || str_contains($override, "\0")) {
            throw new TaskRejectedException("invalid {$env} override");
        }

        return $override;
    }

    private static function have(string $env, string $default): bool
    {
        if (trim((string) (getenv($env) ?: '')) !== '') {
            return true;
        }

        return is_executable($default);
    }

    // ------------------------------------------------------------ paths ------

    /** Zone files live here: /etc/bind/zones/db.<domain> (env: ACP_BIND_ZONE_DIR). */
    public function zoneDir(): string
    {
        return self::pathEnv('ACP_BIND_ZONE_DIR', self::DEFAULT_ZONE_DIR);
    }

    /** The distro's named.conf — we add exactly one `include` line to it. */
    public function confFile(): string
    {
        return self::pathEnv('ACP_BIND_CONF', self::DEFAULT_CONF);
    }

    public function optionsFile(): string
    {
        return self::pathEnv('ACP_BIND_OPTIONS', self::DEFAULT_OPTIONS);
    }

    /** Original named.conf.options before AlphaCP touched it (restored on failure). */
    public function optionsBackupFile(): string
    {
        return $this->optionsFile() . '.acp-orig';
    }

    /** Our own zones file, included from named.conf. */
    public function zonesFile(): string
    {
        return self::pathEnv('ACP_BIND_ZONES', self::DEFAULT_ZONES);
    }

    /** @return string absolute path (relative values are refused, fail closed). */
    private static function pathEnv(string $key, string $default): string
    {
        $value = getenv($key);
        if (!is_string($value) || $value === '' || $value[0] !== '/') {
            return $default;
        }

        return rtrim($value, '/');
    }

    private static function checkconfBin(): string
    {
        return self::which('ACP_BIND_CHECKCONF', self::CHECKCONF);
    }

    private static function checkzoneBin(): string
    {
        return self::which('ACP_BIND_CHECKZONE', self::CHECKZONE);
    }

    private static function rndcBin(): string
    {
        return self::which('ACP_BIND_RNDC', self::RNDC);
    }

    private static function digBin(): string
    {
        return self::which('ACP_BIND_DIG', self::DIG);
    }
}
