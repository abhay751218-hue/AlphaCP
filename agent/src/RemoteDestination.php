<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use Throwable;

/**
 * Remote backup destinations — where this server PUSHES its archives (scp).
 *
 * Mirror image of RemotePull, with the same rules:
 *  - **argv only**: host, user, path and the remote command are separate argv
 *    elements; no shell is ever spawned on this side, so `; rm -rf /` typed
 *    into a field is just a weird remote file name — never a local command.
 *  - **host keys are pinned**: a destination is only saved once the admin has
 *    seen a fingerprint (probe) and pinned it (or explicitly accepted the first
 *    key — logged loudly). Every later test/push re-checks that pin, so a
 *    re-installed or swapped server (MITM) shows up as MISMATCH, not silence.
 *  - **secrets stay on disk, never in argv, never in the result**: the private
 *    key or the password lives in a 0600 file under `etc/backup-keys`; the
 *    config JSON only points at it. Task results therefore never carry one.
 *  - **uploads are atomic on the far side too**: bytes land as `<name>.part`
 *    and are `mv`-ed into place only after the remote `sha256sum` matches the
 *    local one — a half-uploaded archive is removed, never left as a backup.
 */
final class RemoteDestination
{
    public const SSH = '/usr/bin/ssh';
    public const SCP = '/usr/bin/scp';
    public const KEYGEN = '/usr/bin/ssh-keygen';
    public const SSHPASS = '/usr/bin/sshpass';

    public const TEST_TIMEOUT = 60;
    public const BROWSE_TIMEOUT = 60;
    public const PUSH_TIMEOUT = 3600;
    public const CONNECT_TIMEOUT = 15;

    public const MAX_NAME = 32;
    public const MAX_BROWSE = 200;
    public const MAX_PUSH_BYTES = 536_870_912_000; // 500 GiB sanity ceiling

    /** @var list<string> temp files (known_hosts, key copies) removed in the end */
    private array $tempFiles = [];

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
        private readonly string $stateRoot,
    ) {
    }

    // ------------------------------------------------------------------ paths --

    /** Where destination configs live (root-only; the JSON has no secrets). */
    public function configDir(): string
    {
        return rtrim($this->stateRoot, '/') . '/etc/backup-destinations';
    }

    /** Where the SSH private keys / password files live. 0600 files, 0700 dir. */
    public function keyDir(): string
    {
        return rtrim($this->stateRoot, '/') . '/etc/backup-keys';
    }

    // ------------------------------------------------------------------- save --

    /**
     * Create or update a destination.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    public function save(array $spec): array
    {
        $name = self::assertName((string) ($spec['name'] ?? ''));
        $host = RemotePull::assertHost((string) ($spec['host'] ?? ''));
        $user = RemotePull::assertUser((string) ($spec['user'] ?? ''));
        $path = RemotePull::assertRemotePath((string) ($spec['path'] ?? ''));
        $port = (int) ($spec['port'] ?? 22);
        if ($port < 1 || $port > 65535) {
            throw new TaskRejectedException("destination port {$port} is out of range");
        }
        $auth = strtolower(trim((string) ($spec['auth'] ?? 'key')));
        if ($auth !== 'key' && $auth !== 'password') {
            throw new TaskRejectedException("destination auth '{$auth}' nahi chalega (key ya password)");
        }
        $retention = (int) ($spec['retention_days'] ?? 30);
        if ($retention < 1 || $retention > 365) {
            throw new TaskRejectedException('retention_days 1 se 365 ke beech hona chahiye');
        }
        $enabled = ($spec['enabled'] ?? true) !== false;

        $dir = $this->configDir();
        $keys = $this->keyDir();
        $this->ensureDir($dir, 0700);
        $this->ensureDir($keys, 0700);

        $file = $dir . '/' . $name . '.json';
        $existing = $this->loadOrNull($name);

        // 1) host key — pin karo (ya pehli key openly accept karo, log ke saath)
        $fingerprint = trim((string) ($spec['host_fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            if (!self::looksLikeFingerprint($fingerprint)) {
                throw new TaskRejectedException("host_fingerprint '{$fingerprint}' SHA256:... jaisa nahi lagta");
            }
        } elseif (($spec['accept_host_key'] ?? false) === true) {
            $probed = (new RemotePull($this->cmd, $this->log, sys_get_temp_dir()))->probe($host, $port);
            $fingerprint = (string) $probed['fingerprint'];
            $this->log->warning(
                "backup destination '{$name}': host key bina verify hue accept ki gayi ({$host} {$fingerprint})"
            );
        } elseif ($existing !== null && (string) ($existing['host_fingerprint'] ?? '') !== '') {
            $fingerprint = (string) $existing['host_fingerprint'];
        } else {
            throw new TaskRejectedException(
                "destination '{$name}' ke liye host key pin chahiye — pehle backup.pull jaisa probe karo "
                . "(ssh-keyscan) aur host_fingerprint do, ya accept_host_key=true (kam safe)"
            );
        }

        // 2) auth material — disk par 0600, config JSON me sirf uska pointer
        $keyFile = $keys . '/' . $name;
        $passwordFile = $keys . '/' . $name . '.password';
        $publicKey = null;
        if ($auth === 'password') {
            $password = (string) ($spec['password'] ?? '');
            if ($password === '' && !is_file($passwordFile)) {
                throw new TaskRejectedException('password auth chuna gaya par password hi nahi diya');
            }
            if ($password !== '') {
                $this->writeSecret($passwordFile, $password . "\n");
            }
            if (!self::have('ACP_SSH_SSHPASS', self::SSHPASS)) {
                $this->log->warning("backup destination '{$name}': sshpass nahi mila — push/test tab tak fail honge");
            }
            $this->shred($keyFile);
            $this->shred($keyFile . '.pub');
        } else {
            $pem = trim((string) ($spec['private_key'] ?? ''));
            if ($pem !== '') {
                if (!str_contains($pem, 'PRIVATE KEY')) {
                    throw new TaskRejectedException('private_key PEM jaisa nahi lagta (usme "PRIVATE KEY" hona chahiye)');
                }
                if (strlen($pem) > 65536) {
                    throw new TaskRejectedException('private_key bahut bada hai');
                }
                $this->writeSecret($keyFile, $pem . "\n");
            } elseif (!is_file($keyFile)) {
                $publicKey = $this->generateKey($keyFile);
            }
            $this->shred($passwordFile);
        }

        $now = gmdate('c');
        $config = [
            'version'         => 1,
            'name'            => $name,
            'type'            => 'ssh',
            'host'            => $host,
            'port'            => $port,
            'user'            => $user,
            'path'            => $path,
            'auth'            => $auth,
            'retention_days'  => $retention,
            'host_fingerprint' => $fingerprint,
            'enabled'         => $enabled,
            'has_key'         => $auth === 'key' && is_file($keyFile),
            'has_password'    => $auth === 'password' && is_file($passwordFile),
            'created_at'      => is_array($existing) ? (string) ($existing['created_at'] ?? $now) : $now,
            'updated_at'      => $now,
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json) || !$this->writeSecret($file, $json . "\n")) {
            throw new TaskRejectedException("destination config {$file} likhi nahi ja saki");
        }

        $publicKey ??= is_file($keyFile . '.pub') ? (string) @file_get_contents($keyFile . '.pub') : null;

        $out = $config;
        if (is_string($publicKey) && trim($publicKey) !== '') {
            $out['public_key'] = trim($publicKey);
        }

        return $out;
    }

    // ------------------------------------------------------------------- list --

    /** @return list<array<string, mixed>> every saved destination (no secrets). */
    public function all(): array
    {
        $dir = $this->configDir();
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $names[] = basename($file, '.json');
        }
        sort($names);

        $out = [];
        foreach ($names as $name) {
            $cfg = $this->loadOrNull($name);
            if ($cfg !== null) {
                $out[] = $cfg;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function loadOrNull(string $name): ?array
    {
        $name = self::assertName($name);
        $file = $this->configDir() . '/' . $name . '.json';
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string, mixed> */
    public function load(string $name): array
    {
        $cfg = $this->loadOrNull($name);
        if ($cfg === null) {
            throw new TaskRejectedException("destination '{$name}' nahi mili — pehle save karo");
        }

        return $cfg;
    }

    /** Config + the secret it points at (key path / password path). Never returns the secret itself. */
    public function remove(string $name): array
    {
        $cfg = $this->load($name);
        $safe = self::assertName($name);
        $file = $this->configDir() . '/' . $safe . '.json';
        $this->shred($file);
        $this->shred($this->keyDir() . '/' . $safe);
        $this->shred($this->keyDir() . '/' . $safe . '.pub');
        $this->shred($this->keyDir() . '/' . $safe . '.password');

        return ['name' => $safe, 'removed' => !is_file($file), 'host' => (string) ($cfg['host'] ?? '')];
    }

    // ------------------------------------------------------------------- test --

    /** @return array<string, mixed> */
    public function test(string $name): array
    {
        $cfg = $this->load($name);
        $host = (string) ($cfg['host'] ?? '');
        $user = (string) ($cfg['user'] ?? '');
        $path = rtrim((string) ($cfg['path'] ?? ''), '/');
        $port = (int) ($cfg['port'] ?? 22);
        $probed = $this->assertPin($cfg);
        $knownHosts = $this->knownHostsFile($probed);

        $token = bin2hex(random_bytes(8));
        $probe = $path . '/.acp-probe-' . $token;
        // remote side par ek chhota write + read + delete — isse pata chalta hai ki
        // auth chali, path likhne layak hai aur hum wapas saaf kar sakte hain.
        $remote = 'mkdir -p ' . $path
            . ' && echo ' . $token . ' > ' . $probe
            . ' && test "$(cat ' . $probe . ')" = ' . $token
            . ' && rm -f ' . $probe
            . ' && echo ACP-OK';

        $started = microtime(true);
        $res = $this->cmd->run($this->sshArgv($cfg, $remote, $knownHosts), self::TEST_TIMEOUT);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $this->cleanupTemp();

        if (!$res->ok() || !str_contains($res->stdout, 'ACP-OK')) {
            throw new TaskRejectedException(
                "destination '{$name}' test fail ({$user}@{$host}:{$port}): " . self::cleanError($res)
            );
        }

        return [
            'ok'          => true,
            'name'        => (string) ($cfg['name'] ?? $name),
            'host'        => $host,
            'port'        => $port,
            'user'        => $user,
            'path'        => $path,
            'auth'        => (string) ($cfg['auth'] ?? 'key'),
            'fingerprint' => (string) $probed['fingerprint'],
            'key_type'    => (string) $probed['key_type'],
            'duration_ms' => $duration,
        ];
    }

    // ------------------------------------------------------------------ browse --

    /** @return array<string, mixed> */
    public function browse(string $name): array
    {
        $cfg = $this->load($name);
        $path = rtrim((string) ($cfg['path'] ?? ''), '/');
        $probed = $this->assertPin($cfg);
        $knownHosts = $this->knownHostsFile($probed);

        $res = $this->cmd->run($this->sshArgv($cfg, 'ls -1 ' . $path, $knownHosts), self::BROWSE_TIMEOUT);
        $this->cleanupTemp();
        if (!$res->ok()) {
            throw new TaskRejectedException(
                "destination '{$name}' par ls nahi chal saka: " . self::cleanError($res)
            );
        }

        $files = [];
        foreach (preg_split('/\R/', (string) $res->stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line === '.' || $line === '..') {
                continue;
            }
            $base = basename($line);
            if ($base !== $line) {
                continue; // koi ajeeb path aa gaya to chhod do
            }
            if (preg_match('/\.(tar|tar\.gz|tgz)$/i', $base) !== 1) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $base) !== 1) {
                continue;
            }
            $files[] = $base;
            if (count($files) >= self::MAX_BROWSE) {
                break;
            }
        }
        sort($files);

        return [
            'name'    => (string) ($cfg['name'] ?? $name),
            'host'    => (string) ($cfg['host'] ?? ''),
            'path'    => $path,
            'files'   => $files,
            'count'   => count($files),
            'truncated' => count($files) >= self::MAX_BROWSE,
        ];
    }

    // -------------------------------------------------------------------- push --

    /**
     * Upload one local archive to the destination and verify it there.
     *
     * @return array<string, mixed>
     */
    public function push(string $name, string $localFile): array
    {
        $cfg = $this->load($name);
        if (($cfg['enabled'] ?? true) === false) {
            throw new TaskRejectedException("destination '{$name}' disabled hai — pehle enable karo");
        }
        $source = self::assertArchivePath($localFile, $this->stateRoot);
        $probed = $this->assertPin($cfg);
        $knownHosts = $this->knownHostsFile($probed);

        $host = (string) ($cfg['host'] ?? '');
        $user = (string) ($cfg['user'] ?? '');
        $path = rtrim((string) ($cfg['path'] ?? ''), '/');
        $port = (int) ($cfg['port'] ?? 22);
        $base = basename($source);
        $remote = $path . '/' . $base;
        $part = $remote . '.part';

        $sha = hash_file('sha256', $source);
        $bytes = filesize($source);
        if (!is_string($sha) || !is_int($bytes)) {
            throw new TaskRejectedException('archive ka checksum/size nahi nikala ja saka');
        }
        if ($bytes > self::MAX_PUSH_BYTES) {
            throw new TaskRejectedException('archive bahut bada hai push ke liye');
        }

        $started = microtime(true);
        $auth = $this->authPrefix($cfg);
        $argv = array_merge($auth['wrap'], [self::scpBin()]);
        $argv = array_merge($argv, $this->sshBaseArgv($cfg, $knownHosts));
        $argv = array_merge($argv, $auth['head']);
        $argv[] = '-P';
        $argv[] = (string) $port;
        $argv[] = $source;
        $argv[] = $user . '@' . $host . ':' . $part;
        $res = $this->cmd->run($argv, self::PUSH_TIMEOUT);
        if (!$res->ok()) {
            $this->cleanupTemp();
            throw new TaskRejectedException(
                "destination '{$name}' par upload fail ({$user}@{$host}:{$part}): " . self::cleanError($res)
            );
        }

        // remote par atomic rename + checksum: adhoori file kabhi asli backup na bane.
        // Agar yahin kuch bhi fail ho jaye (network, allowlist, checksum) to door ke
        // server par .part FILE NAHI REHNI CHAHIYE — warna backup server me kachra
        // jamta jayega (live check ne ye pakda tha 0.73.0 me).
        $verify = 'mv -f ' . $part . ' ' . $remote . ' && sha256sum ' . $remote;
        try {
            $vres = $this->cmd->run($this->sshArgv($cfg, $verify, $knownHosts), self::BROWSE_TIMEOUT);
            $this->cleanupTemp();
            $remoteSha = '';
            if (preg_match('/([a-f0-9]{64})/', (string) $vres->stdout, $m) === 1) {
                $remoteSha = strtolower($m[1]);
            }
            if (!$vres->ok() || $remoteSha === '' || !hash_equals($sha, $remoteSha)) {
                throw new TaskRejectedException(
                    "destination '{$name}' par checksum mismatch (local {$sha}, remote "
                    . ($remoteSha === '' ? 'koi sha nahi mila' : $remoteSha) . ') — remote file hata di'
                );
            }
        } catch (Throwable $e) {
            $this->cleanupRemote($cfg, $part . ' ' . $remote, $knownHosts, $name);
            $this->cleanupTemp();

            throw $e instanceof TaskRejectedException
                ? $e
                : new TaskRejectedException("destination '{$name}' par upload verify nahi ho saka: " . $e->getMessage());
        }

        return [
            'ok'          => true,
            'name'        => (string) ($cfg['name'] ?? $name),
            'host'        => $host,
            'port'        => $port,
            'user'        => $user,
            'path'        => $path,
            'file'        => $base,
            'remote_path' => $remote,
            'bytes'       => $bytes,
            'sha256'      => $sha,
            'verified'    => true,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Best-effort remote saafai — jab upload adhoora reh gaya ho. Network/allowlist
     * fail ho to bhi koshish karni chahiye; na ho paye to log me likh do (asli
     * galti wapas admin ko dikhni chahiye, ye nahi).
     *
     * @param array<string, mixed> $cfg
     */
    private function cleanupRemote(array $cfg, string $remoteFiles, string $knownHosts, string $name): void
    {
        try {
            $rm = $this->cmd->run(
                $this->sshArgv($cfg, 'rm -f ' . $remoteFiles, $knownHosts),
                self::BROWSE_TIMEOUT,
            );
            if (!$rm->ok()) {
                $this->log->warning("destination '{$name}': adhoora upload remote par reh gaya ({$remoteFiles}) — haath saaf kar lo");
            }
        } catch (Throwable $e) {
            $this->log->warning("destination '{$name}': adhoora upload hata nahi sake ({$remoteFiles}): " . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------- helpers --

    /** Destination name is a slug — it becomes a file name, so keep it boring. */
    public static function assertName(string $name): string
    {
        $n = strtolower(trim($name));
        if ($n === '' || strlen($n) > self::MAX_NAME) {
            throw new TaskRejectedException('destination name khali hai ya 32 char se lamba hai');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $n) !== 1) {
            throw new TaskRejectedException("destination name '{$n}' galat hai (sirf a-z, 0-9 aur -)");
        }

        return $n;
    }

    public static function looksLikeFingerprint(string $fp): bool
    {
        return preg_match('/^(SHA256:)?[A-Za-z0-9+\/]{20,}={0,2}$/', trim($fp)) === 1;
    }

    /**
     * Only files from OUR backup store may be pushed — never anything else on
     * disk. Public + static so the task handler can check it BEFORE the
     * destination is even loaded: a bad path must say "store ke bahar", not
     * "destination nahi mili" (a real live-check found exactly that).
     */
    public static function assertArchivePath(string $file, string $stateRoot): string
    {
        $candidate = trim($file);
        if ($candidate === '' || str_contains($candidate, "\0")) {
            throw new TaskRejectedException('archive_path khali hai');
        }
        if (!str_starts_with($candidate, '/') || str_contains($candidate, '..')) {
            throw new TaskRejectedException("archive_path '{$candidate}' absolute hona chahiye aur '..' se free");
        }
        if (preg_match('#^/[A-Za-z0-9._/-]+$#', $candidate) !== 1) {
            throw new TaskRejectedException("archive_path '{$candidate}' me galat characters hain");
        }
        $base = basename($candidate);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $base) !== 1
            || preg_match('/\.(tar|tar\.gz|tgz)$/i', $base) !== 1) {
            throw new TaskRejectedException("sirf .tar / .tar.gz / .tgz archive push ho sakti hai (mila: {$base})");
        }

        $root = rtrim($stateRoot, '/') . '/backups';
        $realRoot = realpath($root);
        $realFile = realpath($candidate);
        if (!is_string($realRoot) || !is_string($realFile)
            || !str_starts_with($realFile, $realRoot . '/')) {
            throw new TaskRejectedException(
                "archive {$candidate} backup store ({$root}) ke andar nahi hai — wahin se push allowed hai"
            );
        }
        if (is_link($candidate) || !is_file($realFile) || !is_readable($realFile)) {
            throw new TaskRejectedException("archive {$candidate} nahi mila ya padha nahi ja sakta");
        }

        return $realFile;
    }

    /**
     * Host key pin check. A server shows several keys and their order flaps, so
     * the pin may match ANY of them (exactly what OpenSSH does).
     *
     * @param array<string, mixed> $cfg
     * @return array{key_type: string, fingerprint: string, fingerprints: list<string>, pubkey: string}
     */
    private function assertPin(array $cfg): array
    {
        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 22);
        $expected = trim((string) ($cfg['host_fingerprint'] ?? ''));
        if ($expected === '') {
            $label = (string) ($cfg['name'] ?? '?');
            throw new TaskRejectedException("destination '{$label}' me host key pin hi nahi hai");
        }

        $probed = (new RemotePull($this->cmd, $this->log, sys_get_temp_dir()))->probe($host, $port);
        $presented = array_map([RemotePull::class, 'normaliseFingerprint'], $probed['fingerprints']);
        if (!in_array(RemotePull::normaliseFingerprint($expected), $presented, true)) {
            throw new TaskRejectedException(
                "host key MISMATCH for {$host}: pinned {$expected}, server ne ye diye: "
                . implode(', ', $probed['fingerprints'])
                . ' — refuse kar diya (destination dobara save karo naye fingerprint ke saath)'
            );
        }

        return $probed;
    }

    /**
     * ssh/scp common options. Auth (key vs sshpass) is added by authPrefix().
     *
     * @param array<string, mixed> $cfg
     * @return list<string>
     */
    private function sshBaseArgv(array $cfg, string $knownHosts): array
    {
        return [
            '-q',
            '-o', 'UserKnownHostsFile=' . $knownHosts,
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'ConnectTimeout=' . self::CONNECT_TIMEOUT,
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=20',
        ];
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array{head: list<string>, wrap: list<string>}
     */
    private function authPrefix(array $cfg): array
    {
        $auth = (string) ($cfg['auth'] ?? 'key');
        $name = (string) ($cfg['name'] ?? '');
        if ($auth === 'password') {
            $pwFile = $this->keyDir() . '/' . $name . '.password';
            if (!is_file($pwFile)) {
                throw new TaskRejectedException("destination '{$name}' ka password file nahi mila — dobara save karo");
            }
            if (!self::have('ACP_SSH_SSHPASS', self::SSHPASS)) {
                throw new TaskRejectedException('password auth ke liye sshpass chahiye (`apt-get install -y sshpass`)');
            }

            return [
                'head' => ['-o', 'PubkeyAuthentication=no', '-o', 'PreferredAuthentications=password'],
                'wrap' => [self::sshpassBin(), '-f', $pwFile],
            ];
        }

        $keyFile = $this->keyDir() . '/' . $name;
        if (!is_file($keyFile)) {
            throw new TaskRejectedException("destination '{$name}' ki SSH key nahi mili — dobara save karo");
        }

        return [
            'head' => ['-i', $keyFile, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes'],
            'wrap' => [],
        ];
    }

    /**
     * Full ssh argv for one remote command (the command is ONE argv element —
     * the far side runs it in its own shell, we never build a shell here).
     *
     * @param array<string, mixed> $cfg
     * @return list<string>
     */
    private function sshArgv(array $cfg, string $remoteCommand, string $knownHosts): array
    {
        $auth = $this->authPrefix($cfg);
        $argv = array_merge($auth['wrap'], [self::sshBin()]);
        $argv = array_merge($argv, $this->sshBaseArgv($cfg, $knownHosts));
        $argv = array_merge($argv, $auth['head']);
        $argv[] = '-p';
        $argv[] = (string) ((int) ($cfg['port'] ?? 22));
        $argv[] = (string) ($cfg['user'] ?? '') . '@' . (string) ($cfg['host'] ?? '');
        $argv[] = $remoteCommand;

        return $argv;
    }

    /** @param array{key_type: string, fingerprint: string, fingerprints: list<string>, pubkey: string} $probed */
    private function knownHostsFile(array $probed): string
    {
        $file = $this->tempFile(0600);
        file_put_contents($file, $probed['pubkey'] . "\n");

        return $file;
    }

    /** Generate a fresh ed25519 key for this destination; returns the public key. */
    private function generateKey(string $keyFile): string
    {
        if (!self::have('ACP_SSH_KEYGEN', self::KEYGEN)) {
            throw new TaskRejectedException('openssh-client (ssh-keygen) nahi mila — key generate nahi ho saki');
        }
        if (is_file($keyFile)) {
            $this->shred($keyFile);
        }
        $res = $this->cmd->run(
            [self::keygenBin(), '-q', '-t', 'ed25519', '-N', '', '-C', 'alphacp-backup', '-f', $keyFile],
            60,
        );
        if (!$res->ok() || !is_file($keyFile)) {
            throw new TaskRejectedException('destination key generate nahi ho saki: ' . self::cleanError($res));
        }
        @chmod($keyFile, 0600);
        $pub = @file_get_contents($keyFile . '.pub');

        return is_string($pub) ? trim($pub) : '';
    }

    private function writeSecret(string $file, string $contents): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new TaskRejectedException("{$dir} ban nahi saki");
        }
        if (is_link($file)) {
            throw new TaskRejectedException("{$file} ek symlink hai — refuse");
        }
        if (@file_put_contents($file, $contents) === false) {
            return false;
        }
        @chmod($file, 0600);

        return true;
    }

    /** Overwrite before unlink: a key/password should not survive on disk. */
    private function shred(string $file): void
    {
        if (!is_file($file) || is_link($file)) {
            return;
        }
        $size = filesize($file);
        if (is_int($size) && $size > 0 && $size < 1_048_576) {
            @file_put_contents($file, str_repeat("\0", $size));
        }
        @unlink($file);
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

    private function tempFile(int $mode): string
    {
        $file = tempnam(sys_get_temp_dir(), 'acpds');
        if ($file === false) {
            throw new TaskRejectedException('temp file ban nahi payi');
        }
        @chmod($file, $mode);
        $this->tempFiles[] = $file;

        return $file;
    }

    private function cleanupTemp(): void
    {
        foreach ($this->tempFiles as $file) {
            $this->shred($file);
        }
        $this->tempFiles = [];
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

    private static function sshBin(): string
    {
        return self::which('ACP_SSH_BIN', self::SSH);
    }

    private static function scpBin(): string
    {
        return self::which('ACP_SSH_SCP', self::SCP);
    }

    private static function keygenBin(): string
    {
        return self::which('ACP_SSH_KEYGEN', self::KEYGEN);
    }

    private static function sshpassBin(): string
    {
        return self::which('ACP_SSH_SSHPASS', self::SSHPASS);
    }

    private static function cleanError(CommandResult $res): string
    {
        $msg = trim($res->stderr !== '' ? $res->stderr : $res->stdout);
        $msg = preg_replace('/[^\x20-\x7E]+/', ' ', $msg) ?? '';
        $msg = str_replace(['"', "'", '`'], '', $msg);
        $msg = preg_replace('/\s+/', ' ', $msg) ?? '';

        return substr($msg, 0, 300) === '' ? "exit {$res->exitCode}" : substr($msg, 0, 300);
    }
}
