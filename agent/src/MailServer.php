<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use Throwable;

/**
 * S7 — Exim4 (MTA) + Dovecot (IMAP/POP3) for the mailboxes the panel already
 * stores as JSON/bcrypt.
 *
 * The panel/agent already write, per account:
 *   ~/etc/mail/passwd   → `user@domain:{BLF-CRYPT}<bcrypt>:uid:gid::/home/u/mail/d/l::quota`
 *   ~/etc/mail/aliases  → `user@domain: destination`
 *   ~/mail/<domain>/<local>/{cur,new,tmp}
 *
 * Until now nothing consumed them. This class aggregates those per-account
 * files into the two files the real daemons read:
 *   /etc/dovecot/alphacp-users      (Dovecot passwd-file passdb+userdb)
 *   /etc/exim4/alphacp-domains      (domains this server accepts mail for)
 *   /etc/exim4/alphacp-recipients   (`user@domain: /maildir uid gid`)
 *   /etc/exim4/alphacp-aliases      (forwarders)
 *
 * Same rules as BindServer:
 *  - config is built in a temp file, validated (`update-exim4.conf` +
 *    `exim4 -bV`, `doveconf`), and only then put in place; a bad config
 *    restores the backup instead of taking the mail server down;
 *  - argv only, never a shell string;
 *  - idempotent — setup()/sync() can run on every update.
 *
 * Server par exim4 chalu karne se pehle dono taraf se check hota hai:
 * config valid (`exim4 -bV`) aur Dovecot wali (`doveconf -n`).
 */
final class MailServer
{
    // ---- binaries (distro ke hisaab se alag jagah ho sakte hain) ----
    public const EXIM = '/usr/sbin/exim4';
    public const DOVECOT = '/usr/sbin/dovecot';
    public const DOVEADM = '/usr/bin/doveadm';
    public const DOVECONF = '/usr/sbin/doveconf';
    public const UPDATE_EXIM = '/usr/sbin/update-exim4.conf';

    public const EXIM_PATHS = ['/usr/sbin/exim4', '/usr/bin/exim4', '/usr/sbin/exim', '/usr/local/sbin/exim4'];
    public const DOVECOT_PATHS = ['/usr/sbin/dovecot', '/usr/bin/dovecot', '/usr/local/sbin/dovecot'];
    public const DOVEADM_PATHS = ['/usr/bin/doveadm', '/usr/sbin/doveadm', '/usr/local/bin/doveadm'];
    public const DOVECONF_PATHS = ['/usr/sbin/doveconf', '/usr/bin/doveconf', '/usr/local/sbin/doveconf'];
    public const UPDATE_EXIM_PATHS = ['/usr/sbin/update-exim4.conf', '/usr/bin/update-exim4.conf'];
    public const OPENSSL_PATHS = ['/usr/bin/openssl', '/usr/local/bin/openssl'];
    public const SPAMD_PATHS = ['/usr/sbin/spamd', '/usr/bin/spamd', '/usr/local/sbin/spamd'];

    // ---- config files (sab env-overridable: tests kabhi asli /etc ko nahi chhute) ----
    private const DEFAULT_EXIM_TEMPLATE = '/etc/exim4/exim4.conf.template';
    private const DEFAULT_EXIM_DOMAINS = '/etc/exim4/alphacp-domains';
    private const DEFAULT_EXIM_RECIPIENTS = '/etc/exim4/alphacp-recipients';
    private const DEFAULT_EXIM_ALIASES = '/etc/exim4/alphacp-aliases';
    private const DEFAULT_DOVECOT_USERS = '/etc/dovecot/alphacp-users';
    private const DEFAULT_DOVECOT_CONF = '/etc/dovecot/conf.d/99-alphacp.conf';
    private const DEFAULT_EXIM_CATCHALL = '/etc/exim4/alphacp-catchall';
    private const DEFAULT_VACATION_DIR = '/etc/exim4/alphacp-vacation';
    private const DEFAULT_SPAM_DIR = '/etc/exim4/alphacp-spam';
    private const DEFAULT_DKIM_DIR = '/usr/local/alphacp/etc/mail/dkim';

    private const MANAGED_BEGIN = '# >>> AlphaCP managed (mail.server) — haath se edit mat karo';
    private const MANAGED_END = '# <<< AlphaCP managed (mail.server)';

    public const CMD_TIMEOUT = 60;

    /** DKIM selector (DNS me: <selector>._domainkey.<domain>) */
    public const DKIM_SELECTOR = 'default';

    /** DNS blacklists — sirf header + log (reject nahi: DNS issue par mail nahi gire) */
    private const DNSBL = 'zen.spamhaus.org : bl.spamcop.net';
    /** SPF: is server se mail in records se jaati hai (a/mx zone me maujood hain) */
    private const SPF_RECORD = 'v=spf1 a mx -all';

    /** @var list<string> */
    private array $tempFiles = [];

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
    ) {
    }

    // -------------------------------------------------------------- paths ----

    public function eximTemplate(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_TEMPLATE', self::DEFAULT_EXIM_TEMPLATE);
    }

    public function eximTemplateBackup(): string
    {
        return $this->eximTemplate() . '.acp-orig';
    }

    public function domainsFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_DOMAINS', self::DEFAULT_EXIM_DOMAINS);
    }

    public function recipientsFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_RECIPIENTS', self::DEFAULT_EXIM_RECIPIENTS);
    }

    public function aliasesFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_ALIASES', self::DEFAULT_EXIM_ALIASES);
    }

    public function dovecotUsersFile(): string
    {
        return self::pathEnv('ACP_MAIL_DOVECOT_USERS', self::DEFAULT_DOVECOT_USERS);
    }

    /** Catch-all (`*@domain: dest`) — mail.catchall task se. */
    public function catchallFile(): string
    {
        return self::firstNonEmpty('ACP_MAIL_CATCHALL', self::DEFAULT_EXIM_CATCHALL);
    }

    /** Vacation (autoresponder) messages: <dir>/<address>.eml + <address>.repeat */
    public function vacationDir(): string
    {
        return self::firstNonEmpty('ACP_MAIL_VACATION_DIR', self::DEFAULT_VACATION_DIR);
    }

    /** Per-mailbox spam lists: <dir>/<address>.deny , <address>.allow */
    public function spamDir(): string
    {
        return self::firstNonEmpty('ACP_MAIL_SPAM_DIR', self::DEFAULT_SPAM_DIR);
    }

    /** DKIM private keys: <dir>/<domain>.key (+ <domain>.pub) */
    public function dkimDir(): string
    {
        return self::firstNonEmpty('ACP_MAIL_DKIM_DIR', self::DEFAULT_DKIM_DIR);
    }

    public function dovecotConfFile(): string
    {
        return self::pathEnv('ACP_MAIL_DOVECOT_CONF', self::DEFAULT_DOVECOT_CONF);
    }

    private static function pathEnv(string $key, string $default): string
    {
        $value = getenv($key);
        if (!is_string($value) || $value === '' || $value[0] !== '/') {
            return $default;
        }

        return rtrim($value, '/');
    }

    // -------------------------------------------------------------- status ----

    /** @return array<string, mixed> */
    public function status(): array
    {
        $out = [
            'installed'   => $this->installed(),
            'exim'        => null,
            'dovecot'     => null,
            'exim_config' => null,
            'dovecot_config' => null,
            'services'    => ['exim4' => false, 'dovecot' => false],
            'domains'     => 0,
            'mailboxes'   => 0,
            'aliases'     => 0,
        ];
        if (!$out['installed']) {
            return $out + ['error' => 'exim4/dovecot installed nahi hain (updater install karta hai)'];
        }

        $exim = $this->cmd->run([self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bV'], self::CMD_TIMEOUT);
        $out['exim'] = $exim->ok() ? self::firstLine($exim->stdout) : self::cleanError($exim);
        $out['exim_config'] = $exim->ok() ? 'ok' : self::cleanError($exim);

        $dov = $this->cmd->run([self::bin('ACP_MAIL_DOVECOT', self::DOVECOT, self::DOVECOT_PATHS), '--version'], self::CMD_TIMEOUT);
        $out['dovecot'] = $dov->ok() ? trim((string) $dov->stdout) : self::cleanError($dov);

        $dovecotConfig = $this->cmd->run([self::bin('ACP_MAIL_DOVECONF', self::DOVECONF, self::DOVECONF_PATHS), '-n'], self::CMD_TIMEOUT);
        $out['dovecot_config'] = $dovecotConfig->ok() ? 'ok' : self::cleanError($dovecotConfig);

        foreach (['exim4', 'dovecot'] as $unit) {
            $res = $this->cmd->run(['/bin/systemctl', 'is-active', $unit], 20);
            $out['services'][$unit] = $res->ok() && trim((string) $res->stdout) === 'active';
        }

        $out['domains'] = count($this->readLines($this->domainsFile()));
        $out['mailboxes'] = count($this->mailboxLines());
        $out['aliases'] = count($this->readLines($this->aliasesFile()));

        return $out;
    }

    public function installed(): bool
    {
        return self::have('ACP_MAIL_EXIM', self::EXIM_PATHS)
            && self::have('ACP_MAIL_DOVECOT', self::DOVECOT_PATHS)
            && self::have('ACP_MAIL_DOVEADM', self::DOVEADM_PATHS);
    }

    // --------------------------------------------------------------- setup ----

    /**
     * Idempotent provisioning: aggregate files, Dovecot conf, Exim template,
     * validate, then start. Runs fine on every update.
     *
     * @return array<string, mixed>
     */
    public function setup(): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException(
                'exim4/dovecot nahi mile — dhoondha: ' . implode(', ', self::EXIM_PATHS) . ' / '
                . implode(', ', self::DOVECOT_PATHS) . ' (updater install karta hai)'
            );
        }

        $agg = $this->syncFiles();

        // ---- Dovecot: virtual users file + managed conf ----
        $dovecotConf = $this->renderDovecotConf();
        $this->writeManaged($this->dovecotConfFile(), $dovecotConf, 0644);
        $dovecotCheck = $this->cmd->run(
            [self::bin('ACP_MAIL_DOVECONF', self::DOVECONF, self::DOVECONF_PATHS), '-n'],
            self::CMD_TIMEOUT,
        );
        if (!$dovecotCheck->ok()) {
            @unlink($this->dovecotConfFile());
            throw new TaskRejectedException('dovecot config reject: ' . self::cleanError($dovecotCheck));
        }

        // ---- Exim: template (backup ke saath) + generate + validate ----
        if (is_file($this->eximTemplate()) && !is_file($this->eximTemplateBackup())) {
            @copy($this->eximTemplate(), $this->eximTemplateBackup());
        }
        $this->writeManaged($this->eximTemplate(), $this->renderEximTemplate(), 0644);
        $generate = $this->cmd->run(
            [self::bin('ACP_MAIL_UPDATE_EXIM', self::UPDATE_EXIM, self::UPDATE_EXIM_PATHS)],
            self::CMD_TIMEOUT,
        );
        $eximCheck = $this->cmd->run(
            [self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bV'],
            self::CMD_TIMEOUT,
        );
        if (!$generate->ok() || !$eximCheck->ok()) {
            $this->restoreEximTemplate();
            throw new TaskRejectedException(
                'exim config reject (purani config wapas): ' . self::cleanError($generate) . ' / ' . self::cleanError($eximCheck)
            );
        }

        foreach (['exim4', 'dovecot'] as $unit) {
            $this->cmd->run(['/bin/systemctl', 'enable', $unit], self::CMD_TIMEOUT);
            $restart = $this->cmd->run(['/bin/systemctl', 'restart', $unit], self::CMD_TIMEOUT);
            if (!$restart->ok()) {
                $this->cmd->run(['/bin/systemctl', 'start', $unit], self::CMD_TIMEOUT);
            }
        }

        $status = $this->status();
        $configured = $this->markConfigured();

        return [
            'ok'          => true,
            'configured'  => $configured,
            'domains'     => $agg['domains'],
            'mailboxes'   => $agg['mailboxes'],
            'aliases'     => $agg['aliases'],
            'exim_config' => $status['exim_config'] ?? null,
            'dovecot_config' => $status['dovecot_config'] ?? null,
            'services'    => $status['services'] ?? [],
        ];
    }

    /**
     * Rebuild the aggregate files from every account (new mailbox, new domain,
     * new forwarder). Daemons read these files per lookup — no restart needed.
     *
     * @return array<string, int>
     */
    /**
     * Exim kya-kya support karta hai (`exim4 -bV` khud batata hai).
     * DKIM signing aur SpamAssassin isi se gate hote hain — jo cheez binary
     * support na kare, wo config me likhi hi nahi jati (fail-closed).
     *
     * @return array{dkim: bool, content_scanning: bool, spamd: bool}
     */
    public function capabilities(): array
    {
        $bin = self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS);
        $res = $this->cmd->run([$bin, '-bV'], self::CMD_TIMEOUT);
        $text = trim($res->stdout . ' ' . $res->stderr);
        if ($text === '') {
            return ['dkim' => false, 'content_scanning' => false, 'spamd' => false];
        }

        return [
            'dkim'             => str_contains($text, 'DKIM'),
            'content_scanning' => str_contains($text, 'Content_Scanning'),
            'spamd'            => self::have('ACP_MAIL_SPAMD', self::SPAMD_PATHS),
        ];
    }

    /**
     * Mail task (mail.set / mail.forward / mail.catchall / mail.autorespond /
     * mail.spam) ke turant baad: aggregate files dobara banao, taki naya
     * mailbox/forwarder bina kisi alag command ke kaam kare.
     * Mail server configured nahi hai to chupchap skip (task fail nahi hota).
     */
    public static function syncIfConfigured(CommandExecutor $cmd, TaskLogger $log): string
    {
        if (!self::isConfigured()) {
            return 'skipped (mail server configured nahi hai — mail.server setup pehle)';
        }
        try {
            $out = (new self($cmd, $log))->syncFiles();

            return 'ok (' . (int) ($out['mailboxes'] ?? 0) . ' mailboxes, '
                . (int) ($out['aliases'] ?? 0) . ' forwarders)';
        } catch (Throwable $e) {
            // primary task ka kaam ho chuka hai — sync ki wajah se use fail nahi karte
            $log->info('mail sync after task failed: ' . $e->getMessage());

            return 'failed: ' . $e->getMessage();
        }
    }

    /** Updater/task isi se puchhte hain: mail server configure ho chuka hai? */
    public static function isConfigured(): bool
    {
        $root = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');

        return is_file($root . '/etc/mail-server-configured');
    }

    /**
     * mail.deliverability ko asli banana: SPF + DMARC (+ DKIM) records account
     * ki zone me likh kar BIND ko dobara likhna — S9 live hai to `dig` se
     * turant dikh jata hai (hamara daawa nahi, DNS ka jawab).
     *
     * @return array<string, mixed>
     */
    public function deliverability(?string $username = null): array
    {
        $root = AccountPaths::fromEnv()->accountsRoot;
        $homes = [];
        if ($username !== null) {
            $name = strtolower(trim($username));
            if (AccountIdentity::username($name) === null && is_dir($root . '/' . $name)) {
                $homes[] = $root . '/' . $name;
            }
        } else {
            foreach (glob($root . '/*') ?: [] as $dir) {
                if (!is_dir($dir) || is_link($dir)) {
                    continue;
                }
                if (AccountIdentity::username(basename($dir)) !== null) {
                    continue;
                }
                $homes[] = $dir;
            }
        }

        $done = [];
        $failed = [];
        foreach ($homes as $home) {
            $user = basename($home);
            $file = $home . '/etc/mail/deliverability.json';
            if (is_link($file) || !is_file($file)) {
                continue;
            }
            $rows = json_decode((string) @file_get_contents($file), true);
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $domain = strtolower(trim((string) (is_array($row) ? ($row['domain'] ?? '') : (is_string($row) ? $row : ''))));
                if ($domain === '' || !Dns::validDomain($domain)) {
                    continue;
                }
                try {
                    $done[] = $this->applyDeliverability($home, $user, $domain);
                } catch (Throwable $e) {
                    $failed[] = ['domain' => $domain, 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'ok'      => $failed === [],
            'domains' => $done,
            'failed'  => $failed,
            'count'   => count($done),
        ];
    }

    /**
     * Ek domain ke liye: DKIM key (agar nahi to banao) + zone me SPF/DMARC/DKIM
     * records + BIND zone dobara likho.
     *
     * @return array<string, mixed>
     */
    private function applyDeliverability(string $home, string $user, string $domain): array
    {
        $dkim = $this->ensureDkimKey($domain);
        $selector = (string) ($dkim['selector'] ?? self::DKIM_SELECTOR);

        $records = [
            ['domain' => $domain, 'name' => '@', 'type' => 'TXT', 'value' => self::SPF_RECORD],
            ['domain' => $domain, 'name' => '_dmarc', 'type' => 'TXT', 'value' => self::dmarcRecord($domain)],
        ];
        if (is_string($dkim['public'] ?? null) && $dkim['public'] !== '') {
            $records[] = [
                'domain' => $domain,
                'name'   => $selector . '._domainkey',
                'type'   => 'TXT',
                'value'  => 'v=DKIM1; k=rsa; p=' . $dkim['public'],
            ];
        }

        // zone.json: purane SPF/DMARC/DKIM records hatakar naye daalo
        $zoneFile = $home . '/etc/dns/zone.json';
        $existing = [];
        if (is_file($zoneFile) && !is_link($zoneFile)) {
            $decoded = json_decode((string) @file_get_contents($zoneFile), true);
            if (is_array($decoded)) {
                $existing = array_values($decoded);
            }
        }
        $keep = [];
        foreach ($existing as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowDomain = strtolower(trim((string) ($row['domain'] ?? '')));
            $rowName = strtolower(trim((string) ($row['name'] ?? '')));
            $rowType = strtoupper(trim((string) ($row['type'] ?? '')));
            $rowValue = trim((string) ($row['value'] ?? ''));
            if ($rowDomain === $domain && $rowType === 'TXT') {
                $ours = $rowName === '_dmarc'
                    || $rowName === $selector . '._domainkey'
                    || ($rowName === '@' && str_starts_with($rowValue, 'v=spf1'));
                if ($ours) {
                    continue;   // hum inhe abhi dobara likhte hain
                }
            }
            $keep[] = $row;
        }

        $merged = Dns::sanitize(array_merge($keep, $records));
        $dir = dirname($zoneFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $tmp = $dir . '/.zone-' . bin2hex(random_bytes(4)) . '.tmp';
        $body = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $zoneFile)) {
            @unlink($tmp);
            throw new TaskRejectedException("zone.json nahi likh paye ({$domain})");
        }
        @chmod($zoneFile, 0640);
        @chown($zoneFile, $user);
        @chgrp($zoneFile, $user);

        // BIND live hai to zone turant dobara likhi jayegi (`dig` se verify)
        $dns = ['applied' => false];
        $bind = new BindServer($this->cmd, $this->log);
        if ($bind->installed()) {
            try {
                $out = $bind->writeZone($domain, $merged);
                $dns = [
                    'applied'  => true,
                    'records'  => (int) ($out['records'] ?? 0),
                    'verified' => (bool) ($out['verified'] ?? false),
                ];
            } catch (Throwable $e) {
                $dns = ['applied' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'domain'    => $domain,
            'dkim'      => is_string($dkim['public'] ?? null) && $dkim['public'] !== '',
            'selector'  => $selector,
            'dns'       => $dns,
            'records'   => count($merged),
        ];
    }

    /**
     * DKIM key: hai to wahi, nahi to `openssl genrsa` se banao (2048-bit).
     * openssl na mile to key nahi — tab sirf SPF/DMARC likhe jate hain
     * (kabhi jhootha "DKIM on" nahi bataya jata).
     *
     * @return array{selector: string, public: string|null, key: string|null}
     */
    private function ensureDkimKey(string $domain): array
    {
        $dir = $this->dkimDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $keyFile = $dir . '/' . $domain . '.key';
        $pubFile = $dir . '/' . $domain . '.pub';

        if (!is_file($keyFile)) {
            $openssl = self::bin('ACP_MAIL_OPENSSL', '/usr/bin/openssl', self::OPENSSL_PATHS);
            if (!is_executable($openssl)) {
                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => null];
            }
            $tmp = $dir . '/.dkim-' . bin2hex(random_bytes(4)) . '.tmp';
            $made = $this->cmd->run([$openssl, 'genrsa', '-out', $tmp, '2048'], 120);
            if ($made->exitCode !== 0 || !is_file($tmp)) {
                @unlink($tmp);

                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => null];
            }
            @rename($tmp, $keyFile);
            @chmod($keyFile, 0640);
            @chgrp($keyFile, 'Debian-exim');
            $pub = $this->cmd->run([$openssl, 'rsa', '-in', $keyFile, '-pubout', '-out', $pubFile], 60);
            if ($pub->exitCode !== 0 || !is_file($pubFile)) {
                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => $keyFile];
            }
            @chmod($pubFile, 0644);
        } elseif (!is_file($pubFile)) {
            $openssl = self::bin('ACP_MAIL_OPENSSL', '/usr/bin/openssl', self::OPENSSL_PATHS);
            $pub = $this->cmd->run([$openssl, 'rsa', '-in', $keyFile, '-pubout', '-out', $pubFile], 60);
            if ($pub->exitCode !== 0 || !is_file($pubFile)) {
                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => $keyFile];
            }
        }

        $pem = (string) @file_get_contents($pubFile);
        $b64 = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
        if ($b64 === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $b64) !== 1) {
            return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => $keyFile];
        }

        return ['selector' => self::DKIM_SELECTOR, 'public' => $b64, 'key' => $keyFile];
    }

    private static function dmarcRecord(string $domain): string
    {
        return 'v=DMARC1; p=quarantine; adkim=r; aspf=r; rua=mailto:postmaster@' . $domain;
    }

    /**
     * Har account ke ~/etc/mail/* se daemon files banao. Idempotent — kabhi bhi
     * chala sakte ho; exim/dovecot ko restart karne ki zaroorat nahi (dono file
     * har message/auth par padhte hain).
     *
     * @return array<string, mixed>
     */
    public function syncFiles(): array
    {
        $root = AccountPaths::fromEnv()->accountsRoot;
        $users = [];
        $recipients = [];
        $aliases = [];
        $catchalls = [];
        $domains = [];
        $vacation = [];
        $spam = [];

        foreach (glob($root . '/*') ?: [] as $dir) {
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            $username = basename($dir);
            if (AccountIdentity::username($username) !== null) {
                continue;
            }
            $home = $dir;
            $ownBoxes = [];

            foreach ($this->readLines($home . '/etc/mail/passwd') as $line) {
                $fields = explode(':', $line);
                if (count($fields) < 6) {
                    continue;
                }
                $addr = strtolower(trim($fields[0]));
                $uid = trim($fields[2]);
                $gid = trim($fields[3]);
                $maildir = trim($fields[5]);
                $at = strpos($addr, '@');
                if ($at === false || preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $addr) !== 1) {
                    continue;
                }
                if ($maildir === '' || !str_starts_with($maildir, $home . '/')) {
                    continue;   // doosre account ka maildir kabhi accept nahi
                }
                if (!preg_match('/^[0-9]+$/', $uid) || !preg_match('/^[0-9]+$/', $gid)) {
                    continue;
                }
                $users[$addr] = $line;
                $recipients[$addr] = $addr . ': ' . $maildir . ' ' . $uid . ' ' . $gid;
                $domains[substr($addr, $at + 1)] = true;
                $ownBoxes[$addr] = true;
            }

            foreach ($this->readLines($home . '/etc/mail/aliases') as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                [$key, $dest] = explode(':', $line, 2);
                $key = strtolower(trim($key));
                $dest = trim($dest);
                if ($key === '' || $dest === '' || preg_match('/^[a-z0-9._@-]+$/', $key) !== 1) {
                    continue;
                }
                if (preg_match('/^[a-z0-9._@-]+$/', $dest) !== 1) {
                    continue;   // pipe/command nahi — sirf email address
                }
                $aliases[$key] = $key . ': ' . $dest;
            }

            // ---- catch-all (`*@domain: dest`) ----
            foreach ($this->readLines($home . '/etc/mail/catchall') as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                [$key, $dest] = explode(':', $line, 2);
                $key = strtolower(trim($key));
                $dest = trim($dest);
                if (preg_match('/^\*@[a-z0-9.-]+$/', $key) !== 1 || $dest === '') {
                    continue;
                }
                if (preg_match('/^[a-z0-9._@-]+$/', $dest) !== 1) {
                    continue;   // pipe nahi
                }
                $catchalls[$key] = $key . ': ' . $dest;
                $domains[substr($key, 2)] = true;
            }

            // ---- autoresponders (vacation) ----
            foreach ($this->readJsonList($home . '/etc/mail/autorespond') as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $local = strtolower(trim((string) ($row['local'] ?? '')));
                $domain = strtolower(trim((string) ($row['domain'] ?? '')));
                $subject = trim((string) ($row['subject'] ?? ''));
                $body = trim((string) ($row['body'] ?? ''));
                $hours = (int) ($row['interval_h'] ?? 168);
                if ($local === '' || $domain === '' || $subject === '' || $body === '') {
                    continue;
                }
                $addr = $local . '@' . $domain;
                if (preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $addr) !== 1) {
                    continue;
                }
                if (!isset($users[$addr])) {
                    continue;   // bina mailbox ke autoresponder nahi
                }
                $hours = $hours < 1 ? 1 : ($hours > 720 ? 720 : $hours);
                $subject = self::oneLine($subject);
                $vacation[$addr] = [
                    'subject' => $subject,
                    'body'    => $body,
                    'repeat'  => $hours . 'h',
                ];
            }

            // ---- spam blacklist/whitelist: account ke har mailbox par lagu ----
            $spamCfg = $this->readJsonMap($home . '/etc/mail/spam.json');
            if ($spamCfg !== null) {
                foreach (array_keys($ownBoxes) as $addr) {
                    $spam[$addr] = $spamCfg;
                }
            }
        }

        ksort($users);
        ksort($recipients);
        ksort($aliases);
        ksort($catchalls);
        ksort($vacation);
        ksort($spam);
        $domainList = array_keys($domains);
        sort($domainList);

        $this->writeManaged($this->dovecotUsersFile(), $users === [] ? '' : implode("\n", $users) . "\n", 0640, 'dovecot');
        $this->writeManaged($this->recipientsFile(), $recipients === [] ? '' : implode("\n", $recipients) . "\n", 0644);
        $this->writeManaged($this->aliasesFile(), $aliases === [] ? '' : implode("\n", $aliases) . "\n", 0644);
        $this->writeManaged($this->catchallFile(), $catchalls === [] ? '' : implode("\n", $catchalls) . "\n", 0644);
        $this->writeManaged($this->domainsFile(), $domainList === [] ? '' : implode("\n", $domainList) . "\n", 0644);
        $this->writeVacation($vacation);
        $this->writeSpamLists($spam);

        return [
            'domains'    => count($domainList),
            'mailboxes'  => count($users),
            'aliases'    => count($aliases),
            'catchalls'  => count($catchalls),
            'responders' => count($vacation),
            'spam_lists' => count($spam),
        ];
    }

    /** @param array<string, array{subject: string, body: string, repeat: string}> $vacation */
    private function writeVacation(array $vacation): void
    {
        $dir = $this->vacationDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $keep = [];
        foreach ($vacation as $addr => $row) {
            $eml = 'Subject: ' . $row['subject'] . "\n"
                . 'Auto-Submitted: auto-replied' . "\n"
                . 'Precedence: bulk' . "\n"
                . "\n" . $row['body'] . "\n";
            $this->writeManaged($dir . '/' . $addr . '.eml', $eml, 0644);
            $this->writeManaged($dir . '/' . $addr . '.repeat', $row['repeat'], 0644);
            $keep[$addr . '.eml'] = true;
            $keep[$addr . '.repeat'] = true;
        }
        // purane autoresponder ke files hatana zaroori hai — warna deleted
        // responder ke jawab jaate rahenge
        foreach ((array) glob($dir . '/*') as $file) {
            $name = basename((string) $file);
            if (!isset($keep[$name]) && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @param array<string, array{deny: list<string>, allow: list<string>}> $spam */
    private function writeSpamLists(array $spam): void
    {
        $dir = $this->spamDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $keep = [];
        foreach ($spam as $addr => $cfg) {
            foreach (['deny', 'allow'] as $kind) {
                $lines = [];
                foreach ($cfg[$kind] as $entry) {
                    $entry = strtolower(trim((string) $entry));
                    if (preg_match('/^[a-z0-9._%@*-]+$/', $entry) === 1) {
                        $lines[] = $entry;
                    }
                }
                $name = $addr . '.' . $kind;
                $keep[$name] = true;
                if ($lines === []) {
                    if (is_file($dir . '/' . $name)) {
                        @unlink($dir . '/' . $name);
                    }
                    continue;
                }
                $this->writeManaged($dir . '/' . $name, implode("\n", $lines) . "\n", 0644);
            }
        }
        foreach ((array) glob($dir . '/*') as $file) {
            $name = basename((string) $file);
            if (!isset($keep[$name]) && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @return list<mixed> */
    private function readJsonList(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return array{deny: list<string>, allow: list<string>}|null */
    private function readJsonMap(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $deny = is_array($decoded['blacklist'] ?? null) ? array_values($decoded['blacklist']) : [];
        $allow = is_array($decoded['whitelist'] ?? null) ? array_values($decoded['whitelist']) : [];
        if ($deny === [] && $allow === []) {
            return null;
        }

        return ['deny' => $deny, 'allow' => $allow];
    }

    private static function oneLine(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';

        return trim(substr($text, 0, 200));
    }

    /**
     * Sachchi tasdeeq: exim ka routing test (`exim4 -bt`) + Dovecot ka user
     * lookup (`doveadm user`). Dono asli daemons se poochhte hain.
     *
     * @return array<string, mixed>
     */
    public function verify(string $address): array
    {
        $address = strtolower(trim($address));
        if (preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $address) !== 1) {
            throw new TaskRejectedException('invalid email address');
        }
        if (!$this->installed()) {
            throw new TaskRejectedException('exim4/dovecot nahi mile');
        }

        $route = $this->cmd->run(
            [self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bt', $address],
            self::CMD_TIMEOUT,
        );
        $user = $this->cmd->run(
            [self::bin('ACP_MAIL_DOVEADM', self::DOVEADM, self::DOVEADM_PATHS), 'user', $address],
            self::CMD_TIMEOUT,
        );

        $routed = $route->ok()
            && !str_contains((string) $route->stdout, 'cannot be resolved')
            && !str_contains((string) $route->stdout, 'Unrouteable address');

        return [
            'address'  => $address,
            'route'    => trim((string) $route->stdout),
            'routed'   => $routed,
            'mailbox'  => trim((string) $user->stdout),
            'has_mailbox' => $user->ok() && trim((string) $user->stdout) !== '',
        ];
    }

    /** @return list<string> */
    public function mailboxes(): array
    {
        $out = [];
        foreach ($this->mailboxLines() as $line) {
            $fields = explode(':', $line);
            $addr = strtolower(trim($fields[0] ?? ''));
            if ($addr !== '') {
                $out[] = $addr;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ rendering ----

    private function renderDovecotConf(): string
    {
        $users = $this->dovecotUsersFile();
        $lines = [
            self::MANAGED_BEGIN,
            '# AlphaCP S7 — virtual mailboxes jo panel banata hai',
            '# har account ka ~/etc/mail/passwd yahan aggregate hota hai',
            'passdb {',
            '  driver = passwd-file',
            '  args = scheme=BLF-CRYPT ' . $users,
            '}',
            'userdb {',
            '  driver = passwd-file',
            '  args = scheme=BLF-CRYPT ' . $users,
            '}',
            '# mailbox khud Maildir hai (home field usi ko point karta hai)',
            'mail_location = maildir:~/',
            'mail_home = %h',
            'protocols = imap pop3',
            'listen = 127.0.0.1, ::1',
            'disable_plaintext_auth = no',
            'auth_mechanisms = plain login',
            'mail_plugins = $mail_plugins quota',
            'protocol imap {',
            '  mail_plugins = $mail_plugins imap_quota',
            '}',
            'service imap-login {',
            '  inet_listener imap {',
            '    port = 143',
            '  }',
            '}',
            'service pop3-login {',
            '  inet_listener pop3 {',
            '    port = 110',
            '  }',
            '}',
            self::MANAGED_END,
        ];

        return implode("\n", $lines) . "\n";
    }

    private function renderEximTemplate(): string
    {
        $domains = $this->domainsFile();
        $recipients = $this->recipientsFile();
        $aliases = $this->aliasesFile();
        $catchall = $this->catchallFile();
        $vacation = $this->vacationDir();
        $spamDir = $this->spamDir();
        $dkimDir = $this->dkimDir();
        $caps = $this->capabilities();
        $dnsbl = self::DNSBL;
        $dkimKey = $dkimDir . '/\$sender_address_domain.key';
        $dkim = $caps['dkim']
            ? "  dkim_domain = \$sender_address_domain\n"
              . '  dkim_selector = ' . self::DKIM_SELECTOR . "\n"
              . "  dkim_canon = relaxed\n"
              . "  dkim_private_key = \${if exists{{$dkimKey}}{{$dkimKey}}{0}}\n"
              . "  dkim_sign_headers = Date:From:To:Subject:Message-ID:MIME-Version:Content-Type\n"
            : '';
        $spamAcl = $caps['content_scanning'] && $caps['spamd']
            ? "  deny condition = \${if >{\$spam_score_int}{80}{yes}{no}}\n"
              . "       message = This message scored \$spam_score spam points (limit 8.0)\n"
              . "       spam = nobody:true\n\n          "
            : '';

        return <<<EXIM
        # AlphaCP managed exim4 configuration (mail.server)
        # Asli distro wali template ki copy: {$this->eximTemplateBackup()}
        # Ye file har update par dobara likhi jati hai — haath se edit mat karo.

        # 25 par sab interfaces (incoming mail isi se aati hai).
        # IPv6 na ho to Exim sirf warning dekar IPv4 par chal deta hai.
        local_interfaces = <; 0.0.0.0 ; ::0
        domainlist local_domains = lsearch;{$domains}
        domainlist relay_to_domains =
        hostlist   relay_from_hosts = 127.0.0.1 : ::1

        acl_smtp_rcpt = acl_check_rcpt
        acl_smtp_data = acl_check_data

        never_users = root
        host_lookup = *
        rfc1413_hosts =
        rfc1413_query_timeout = 0s
        ignore_bounce_errors_after = 2d
        timeout_frozen_after = 7d
        smtp_banner = \$smtp_active_hostname ESMTP AlphaCP
        message_size_limit = 50M

        begin acl

        acl_check_rcpt:
          accept hosts = : +relay_from_hosts

          # account ki blacklist (mail.spam task) — sender blocked
          deny senders = \${if exists{{$spamDir}/\$local_part@\$domain.deny}{wildlsearch;{$spamDir}/\$local_part@\$domain.deny}{}}
               message = sender is blocked for this mailbox

          # account ki whitelist — aage ke checks chhodo
          accept senders = \${if exists{{$spamDir}/\$local_part@\$domain.allow}{wildlsearch;{$spamDir}/\$local_part@\$domain.allow}{}}

          deny domains = +local_domains
               local_parts = ^[./|] : ^.*[@%!/|`#&?] : ^.*/\\.\\./
               message = restricted characters in address

          accept domains = +local_domains
                 endpass
                 verify = recipient

          deny message = relay not permitted

        acl_check_data:
          # DNS blacklist: reject nahi, header + log (DNS slow ho to mail nahi girti)
          warn dnslists = {$dnsbl}
               add_header = X-AlphaCP-DNSBL: \$dnslist_domain (\$dnslist_value)
               log_message = DNSBL hit \$dnslist_domain for \$sender_address -> \$local_part@\$domain

          {$spamAcl}accept

        begin routers

        # 1) forwarders (aliases) — pehle ye, phir mailbox
        alphacp_aliases:
          driver = redirect
          domains = +local_domains
          allow_fail
          allow_defer
          qualify_preserve_domain
          retry_use_local_part
          data = \${lookup{\$local_part@\$domain}lsearch{{$aliases}}}

        # 2) autoresponder (vacation) — unseen: mail delivery aage bhi hoti hai
        alphacp_autoreply:
          driver = accept
          domains = +local_domains
          condition = \${if exists{{$vacation}/\$local_part@\$domain.eml}{yes}{no}}
          senders = ! ^.*-request@.* : ! ^bounce-.*@.* : ! ^.*-bounce@.* : \
                    ! ^owner-.*@.* : ! ^postmaster@.* : ! ^webmaster@.* : \
                    ! ^listmaster@.* : ! ^mailer-daemon@.* : ! ^root@.* : \
                    ! ^nobody@.*
          transport = alphacp_vacation
          unseen
          no_expn
          no_verify

        # 3) asli mailbox -> Maildir (uid/gid mailbox ke hisaab se)
        alphacp_mailbox:
          driver = accept
          domains = +local_domains
          condition = \${if !eq{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}{}{yes}{no}}
          transport = alphacp_maildir
          no_more

        # 4) catch-all (*@domain) — mailbox na mile to yahi aakhri rasta
        alphacp_catchall:
          driver = redirect
          domains = +local_domains
          allow_fail
          allow_defer
          qualify_preserve_domain
          data = \${lookup{*@\$domain}lsearch{{$catchall}}}

        # 5) server ke apne system users (root, ubuntu, ...) — /etc/aliases bhi
        system_aliases:
          driver = redirect
          allow_fail
          allow_defer
          data = \${lookup{\$local_part}lsearch{/etc/aliases}}
          file_transport = address_file
          pipe_transport = address_pipe

        local_user:
          driver = accept
          check_local_user
          transport = maildir_home
          cannot_route_message = Unknown user

        # 6) bahar ki duniya
        dnslookup:
          driver = dnslookup
          domains = ! +local_domains
          transport = remote_smtp
          ignore_target_hosts = 0.0.0.0 : 127.0.0.0/8
          no_more

        begin transports

        alphacp_maildir:
          driver = appendfile
          maildir_format
          create_directory = false
          directory = \${extract{1}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          user = \${extract{2}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          group = \${extract{3}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          delivery_date_add
          envelope_to_add
          return_path_add
          mode = 0600

        alphacp_vacation:
          driver = autoreply
          file = {$vacation}/\$local_part@\$domain.eml
          file_expand
          from = \$local_part@\$domain
          to = \$sender_address
          subject = \${if def:h_subject: {Re: \$h_subject:}{Automatic reply}}
          once = /var/spool/exim4/db/alphacp-vacation-\$local_part@\$domain
          once_repeat = \${if exists{{$vacation}/\$local_part@\$domain.repeat}{\${readfile{{$vacation}/\$local_part@\$domain.repeat}{}}}{7d}}
          log = /var/log/exim4/vacation.log
          return_message

        maildir_home:
          driver = appendfile
          maildir_format
          directory = \$home/Maildir
          delivery_date_add
          envelope_to_add
          return_path_add
          user = \$local_part
          group = mail
          mode = 0600

        address_file:
          driver = appendfile
          delivery_date_add
          envelope_to_add
          return_path_add

        address_pipe:
          driver = pipe
          return_output

        remote_smtp:
          driver = smtp
          {$dkim}
        begin retry
        *                      *           F,2h,15m; G,16h,1h,1.5; F,4d,6h

        begin rewrite

        begin authenticators

        # S7 slice 2 me: Dovecot SASL ke saath submission (587) + SMTP auth.
        # Tab tak sirf localhost se relay (webmail/Roundcube) — koi open relay nahi.
        EXIM;
    }

    // -------------------------------------------------------------- helpers ----

    /** @return list<string> */
    private function mailboxLines(): array
    {
        return $this->readLines($this->dovecotUsersFile());
    }

    /** @return list<string> */
    private function readLines(string $file): array
    {
        if (is_link($file) || !is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $lines = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Atomic-ish write: temp file -> rename (kabhi aadhi file nahi banti). */
    private function writeManaged(string $file, string $body, int $mode, ?string $group = null): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $dir . '/.acp-mail-' . bin2hex(random_bytes(4)) . '.tmp';
        $this->tempFiles[] = $tmp;
        if (@file_put_contents($tmp, $body) === false) {
            throw new TaskRejectedException("mail config temp file nahi likhi ja saki: {$tmp}");
        }
        @chmod($tmp, $mode);
        if ($group !== null) {
            @chgrp($tmp, $group);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new TaskRejectedException("mail config file nahi likhi ja saki: {$file}");
        }
        @chmod($file, $mode);
        if ($group !== null) {
            @chgrp($file, $group);
        }
    }

    private function restoreEximTemplate(): void
    {
        if (is_file($this->eximTemplateBackup())) {
            @copy($this->eximTemplateBackup(), $this->eximTemplate());
            $this->cmd->run(
                [self::bin('ACP_MAIL_UPDATE_EXIM', self::UPDATE_EXIM, self::UPDATE_EXIM_PATHS)],
                self::CMD_TIMEOUT,
            );
        }
    }

    /** Updater isi file se janta hai ki mail configure ho chuka hai (services chalu rakhni hain). */
    private function markConfigured(): bool
    {
        $root = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');
        $dir = $root . '/etc';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/mail-server-configured';

        return @file_put_contents($file, 'configured ' . gmdate('c') . "\n") !== false;
    }

    private static function firstNonEmpty(string $env, string $default): string
    {
        $override = trim((string) (getenv($env) ?: ''));
        if ($override !== '' && $override[0] === '/' && !str_contains($override, "\0")) {
            return rtrim($override, '/');
        }

        return $default;
    }

    /** @param list<string> $paths */
    private static function bin(string $env, string $default, array $paths): string
    {
        $override = trim((string) (getenv($env) ?: ''));
        if ($override !== '') {
            if ($override[0] !== '/' || str_contains($override, "\0")) {
                throw new TaskRejectedException("invalid {$env} override");
            }

            return $override;
        }
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return $default;
    }

    /** @param list<string> $paths */
    private static function have(string $env, array $paths): bool
    {
        if (trim((string) (getenv($env) ?: '')) !== '') {
            return true;
        }
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return true;
            }
        }

        return false;
    }

    private static function firstLine(string $text): string
    {
        $line = strtok($text, "\n");

        return $line === false ? trim($text) : trim($line);
    }

    private static function cleanError(CommandResult $res): string
    {
        $msg = trim($res->stderr !== '' ? $res->stderr : $res->stdout);
        $msg = preg_replace('/[^\x20-\x7E]+/', ' ', $msg) ?? '';
        $msg = preg_replace('/\s+/', ' ', $msg) ?? '';

        return substr($msg, 0, 300) === '' ? "exit {$res->exitCode}" : substr($msg, 0, 300);
    }
}
