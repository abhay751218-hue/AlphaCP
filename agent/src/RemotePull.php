<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Remote pull — a cPanel archive from another server over SSH (scp).
 *
 * Why this shape (same rules as the rest of the agent):
 *  - **argv only**: every remote detail (host, user, path) is one argv element;
 *    no shell string is ever built, so `; rm -rf /` in a field is just a weird
 *    filename on the far side — never a command here.
 *  - **host keys are pinned**: `probe()` shows the fingerprint first, `pull()`
 *    refuses to connect unless it matches (or the admin explicitly accepts the
 *    first key — that decision is logged loudly). Plain `known_hosts` TOFU is
 *    never silent.
 *  - **secrets never touch argv**: an inline private key goes to a 0600 temp
 *    file, a password goes to a 0600 file read by `sshpass -f`, and both are
 *    deleted in `finally`. Nothing is written to the log.
 *  - **downloads are atomic**: bytes land in `<drop>/.acp-pull-<stamp>.part`
 *    and are renamed into place only after the (optional) checksum matches, so
 *    a half-downloaded archive can never be picked up by an import.
 */
final class RemotePull
{
    public const KEYSCAN = '/usr/bin/ssh-keyscan';
    public const KEYGEN = '/usr/bin/ssh-keygen';
    public const SCP = '/usr/bin/scp';
    public const SSHPASS = '/usr/bin/sshpass';

    public const PROBE_TIMEOUT = 25;
    public const PULL_TIMEOUT = 3600;
    public const CONNECT_TIMEOUT = 15;
    public const MIN_FREE_BYTES = 67_108_864;      // 64 MiB reserve in the drop dir
    public const MAX_NAME = 120;

    /** @var list<string> temp files that must go away whatever happens */
    private array $tempFiles = [];

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
        private readonly string $dropRoot,
    ) {
    }

    // --------------------------------------------------------------- binaries --

    /**
     * Binary resolution with a test/odd-distro escape hatch (same idea as
     * MysqlServer's ACP_MYSQL_CLIENT): production still cannot execute anything
     * else, because CommandRunner validates argv[0] against its own allowlist.
     */
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

    /** True when the binary exists — or when an override was given explicitly. */
    private static function have(string $env, string $default): bool
    {
        if (trim((string) (getenv($env) ?: '')) !== '') {
            return true;
        }

        return is_executable($default);
    }

    private static function keyscanBin(): string
    {
        return self::which('ACP_SSH_KEYSCAN', self::KEYSCAN);
    }

    private static function keygenBin(): string
    {
        return self::which('ACP_SSH_KEYGEN', self::KEYGEN);
    }

    private static function scpBin(): string
    {
        return self::which('ACP_SSH_SCP', self::SCP);
    }

    private static function sshpassBin(): string
    {
        return self::which('ACP_SSH_SSHPASS', self::SSHPASS);
    }

    // ------------------------------------------------------------------ input --

    /** Hostname or IP. No spaces, no shell metacharacters, no userinfo. */
    public static function assertHost(string $host): string
    {
        $h = strtolower(trim($host));
        if ($h === '' || strlen($h) > 253) {
            throw new TaskRejectedException('remote host is missing or too long');
        }
        $isIp = filter_var($h, FILTER_VALIDATE_IP) !== false;
        $isName = preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $h) === 1;
        if (!$isIp && !$isName) {
            throw new TaskRejectedException("remote host '{$h}' is not a valid hostname or IP");
        }

        return $h;
    }

    /** Absolute path on the REMOTE server. Kept boring on purpose. */
    public static function assertRemotePath(string $path): string
    {
        $p = trim($path);
        if ($p === '' || str_contains($p, "\0")) {
            throw new TaskRejectedException('remote path is missing');
        }
        if (preg_match('#^/[A-Za-z0-9._/-]+$#', $p) !== 1 || str_contains($p, '..')) {
            throw new TaskRejectedException("remote path '{$p}' must be absolute and free of '..'");
        }

        return $p;
    }

    /** Name the archive gets in the drop dir — must look like a tarball. */
    public static function assertDestName(string $name): string
    {
        $n = basename(trim($name));
        if ($n === '' || strlen($n) > self::MAX_NAME) {
            throw new TaskRejectedException('destination file name is missing or too long');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $n) !== 1) {
            throw new TaskRejectedException("destination file name '{$n}' has unsupported characters");
        }
        if (preg_match('/\.(tar|tar\.gz|tgz)$/i', $n) !== 1) {
            throw new TaskRejectedException("destination file name '{$n}' must end in .tar, .tar.gz or .tgz");
        }

        return $n;
    }

    /** Remote SSH user: POSIX-ish, no metacharacters. */
    public static function assertUser(string $user): string
    {
        $u = trim($user);
        if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $u) !== 1) {
            throw new TaskRejectedException("remote user '{$u}' is not a valid SSH user name");
        }

        return $u;
    }

    /** Normalise `SHA256:...` (with or without the prefix, any case). */
    public static function normaliseFingerprint(string $fp): string
    {
        $f = trim($fp);
        if (str_starts_with(strtoupper($f), 'SHA256:')) {
            $f = substr($f, 7);
        }

        return strtolower(preg_replace('/[^A-Za-z0-9+\/=]+/', '', $f) ?? '');
    }

    // ------------------------------------------------------------------ probe --

    /**
     * Fetch the SSH host keys of a remote server WITHOUT downloading anything,
     * so the panel can show a fingerprint and the admin can pin it.
     *
     * A server usually advertises SEVERAL host keys (ed25519 + ecdsa + rsa) and
     * `ssh-keyscan` does not promise a stable order between runs — taking "the
     * first line" makes the fingerprint flap between probe and pull (that is a
     * real bug the live check caught). So we scan them all, report every
     * fingerprint, and put the strongest key (ed25519) first.
     *
     * @return array{host: string, port: int, key_type: string, fingerprint: string,
     *               fingerprints: list<string>, pubkey: string}
     */
    public function probe(string $host, int $port = 22): array
    {
        $host = self::assertHost($host);
        if ($port < 1 || $port > 65535) {
            throw new TaskRejectedException("remote port {$port} is out of range");
        }
        if (!self::have('ACP_SSH_KEYSCAN', self::KEYSCAN) || !self::have('ACP_SSH_KEYGEN', self::KEYGEN)) {
            throw new TaskRejectedException('openssh-client (ssh-keyscan/ssh-keygen) is not installed on this server');
        }

        $res = $this->cmd->run(
            [self::keyscanBin(), '-p', (string) $port, '-t', 'ed25519,ecdsa,rsa', $host],
            self::PROBE_TIMEOUT,
        );
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) $res->stdout) ?: []),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
        if ($res->exitCode !== 0 || $lines === []) {
            throw new TaskRejectedException(
                "host key of {$host}:{$port} could not be read (ssh reachable? port open?): "
                . trim((string) $res->stderr)
            );
        }

        $keys = [];
        foreach ($lines as $line) {
            $one = $this->tempFile(0600);
            file_put_contents($one, $line . "\n");
            $fp = $this->cmd->run([self::keygenBin(), '-l', '-E', 'sha256', '-f', $one], self::PROBE_TIMEOUT);
            $this->forget($one);
            if (preg_match('/(SHA256:[A-Za-z0-9+\/]+)/', (string) $fp->stdout, $m) !== 1) {
                continue;
            }
            $type = 'UNKNOWN';
            if (preg_match('/\((ED25519|ECDSA|RSA|DSA)\)/i', (string) $fp->stdout, $t) === 1) {
                $type = strtoupper($t[1]);
            }
            $keys[] = ['type' => $type, 'fingerprint' => $m[1], 'pubkey' => $line];
        }
        if ($keys === []) {
            throw new TaskRejectedException("host key fingerprint of {$host} could not be computed");
        }
        usort($keys, static fn (array $a, array $b): int => self::keyRank($a['type']) <=> self::keyRank($b['type']));

        return [
            'host' => $host,
            'port' => $port,
            'key_type' => $keys[0]['type'],
            'fingerprint' => $keys[0]['fingerprint'],
            'fingerprints' => array_column($keys, 'fingerprint'),
            'pubkey' => implode("\n", array_column($keys, 'pubkey')),
        ];
    }

    /** Strongest first: ed25519 > ecdsa > rsa > dsa. */
    private static function keyRank(string $type): int
    {
        return match (strtoupper($type)) {
            'ED25519' => 0,
            'ECDSA' => 1,
            'RSA' => 2,
            'DSA' => 3,
            default => 9,
        };
    }

    // ------------------------------------------------------------------- pull --

    /**
     * Download one remote file into the import drop dir.
     *
     * @param array{
     *   host: string, port?: int, user: string, remote_path: string,
     *   auth?: string, private_key?: string, key_path?: string, password?: string,
     *   dest_name?: string, sha256?: string, host_fingerprint?: string,
     *   accept_host_key?: bool, max_kbps?: int, overwrite?: bool
     * } $spec
     * @return array{path: string, name: string, bytes: int, sha256: string, host: string,
     *               port: int, user: string, fingerprint: string, key_type: string,
     *               auth: string, duration_ms: int}
     */
    public function pull(array $spec): array
    {
        try {
            return $this->doPull($spec);
        } finally {
            $this->cleanupTemp();
        }
    }

    /** @param array<string, mixed> $spec */
    private function doPull(array $spec): array
    {
        $host = self::assertHost((string) ($spec['host'] ?? ''));
        $port = (int) ($spec['port'] ?? 22);
        if ($port < 1 || $port > 65535) {
            throw new TaskRejectedException("remote port {$port} is out of range");
        }
        $user = self::assertUser((string) ($spec['user'] ?? ''));
        $remotePath = self::assertRemotePath((string) ($spec['remote_path'] ?? ''));
        $auth = strtolower(trim((string) ($spec['auth'] ?? 'key')));
        if (!in_array($auth, ['key', 'password'], true)) {
            throw new TaskRejectedException("remote auth must be 'key' or 'password'");
        }

        if (!is_dir($this->dropRoot) && !mkdir($this->dropRoot, 0750, true) && !is_dir($this->dropRoot)) {
            throw new TaskRejectedException("import drop dir {$this->dropRoot} could not be created");
        }
        // destination: validate BEFORE we talk to the network, so a bad name costs
        // nothing and can never leave a half-open connection behind
        $destName = trim((string) ($spec['dest_name'] ?? ''));
        if ($destName === '') {
            $destName = basename($remotePath);      // /home/cpmove-alice.tar.gz -> cpmove-alice.tar.gz
        }
        $destName = self::assertDestName($destName);
        $final = rtrim($this->dropRoot, '/') . '/' . $destName;
        if (is_file($final) && ($spec['overwrite'] ?? false) !== true) {
            throw new TaskRejectedException("{$final} already exists — overwrite=true do ya koi aur naam chuno");
        }

        if (!self::have('ACP_SSH_SCP', self::SCP)) {
            throw new TaskRejectedException('openssh-client (scp) is not installed on this server');
        }

        // Auth material pehle hi check ho jaye — network/dns fail hone se pehle, taaki
        // error seedha kahe "key nahi di" na ki "host nahi mila".
        $keyFile = null;
        if ($auth === 'password') {
            if (!self::have('ACP_SSH_SSHPASS', self::SSHPASS)) {
                throw new TaskRejectedException('password auth ke liye sshpass chahiye (`apt-get install -y sshpass`) — ya key auth use karo');
            }
            if (trim((string) ($spec['password'] ?? '')) === '') {
                throw new TaskRejectedException('password auth chuna gaya par password hi nahi diya');
            }
        } else {
            $keyFile = $this->keyFile($spec);
        }

        // 1) host key — pinned, or explicitly accepted on first contact.
        // Ek server kai keys dikha sakta hai (ed25519/ecdsa/rsa) aur keyscan ka order
        // stable nahi hota, isliye pin ka kisi bhi presented key se match hona kaafi
        // hai (OpenSSH bhi yahi karta hai) — nahi to MISMATCH.
        $probed = $this->probe($host, $port);
        $expected = trim((string) ($spec['host_fingerprint'] ?? ''));
        if ($expected !== '') {
            $presented = array_map([self::class, 'normaliseFingerprint'], $probed['fingerprints']);
            if (!in_array(self::normaliseFingerprint($expected), $presented, true)) {
                throw new TaskRejectedException(
                    "host key MISMATCH for {$host}: expected {$expected}, server ne ye diye: "
                    . implode(', ', $probed['fingerprints'])
                    . ' — refuse kar diya (MITM ya server reinstall; naya fingerprint pin karne ke liye dobara probe karo)'
                );
            }
        } elseif (($spec['accept_host_key'] ?? false) !== true) {
            throw new TaskRejectedException(
                "host key of {$host} is not pinned ({$probed['key_type']} {$probed['fingerprint']}) "
                . "— pehle probe karo, fingerprint verify karo, phir 'host_fingerprint' ke saath pull karo"
            );
        } else {
            $this->log->warning(
                "remote pull: accepting an unpinned host key for {$host} ({$probed['key_type']} {$probed['fingerprint']})"
            );
        }
        $knownHosts = $this->tempFile(0600);
        file_put_contents($knownHosts, $probed['pubkey'] . "\n");

        // 2) destination — a .part file is only renamed once the bytes check out
        $part = rtrim($this->dropRoot, '/') . '/.acp-pull-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.part';
        $this->tempFiles[] = $part;

        $free = disk_free_space($this->dropRoot);
        if (is_float($free) && $free < self::MIN_FREE_BYTES) {
            throw new TaskRejectedException('import drop dir me jagah nahi (64 MiB se kam) — pehle purane archive hatao');
        }

        // 3) argv — scp never sees a shell
        $argv = [self::scpBin(), '-q', '-o', 'UserKnownHostsFile=' . $knownHosts, '-o', 'StrictHostKeyChecking=yes'];
        if ($auth === 'password') {
            $pwFile = $this->tempFile(0600);
            file_put_contents($pwFile, ((string) $spec['password']) . "\n");
            array_unshift($argv, self::sshpassBin(), '-f', $pwFile);
            $argv[] = '-o';
            $argv[] = 'PubkeyAuthentication=no';
            $argv[] = '-o';
            $argv[] = 'PreferredAuthentications=password';
        } else {
            $argv[] = '-i';
            $argv[] = (string) $keyFile;
            $argv[] = '-o';
            $argv[] = 'IdentitiesOnly=yes';
            $argv[] = '-o';
            $argv[] = 'BatchMode=yes';
        }
        $argv[] = '-o';
        $argv[] = 'ConnectTimeout=' . self::CONNECT_TIMEOUT;
        $argv[] = '-o';
        $argv[] = 'ServerAliveInterval=30';
        $argv[] = '-o';
        $argv[] = 'ServerAliveCountMax=20';
        $maxKbps = (int) ($spec['max_kbps'] ?? 0);
        if ($maxKbps > 0) {
            $argv[] = '-l';
            $argv[] = (string) min($maxKbps, 1_000_000);
        }
        $argv[] = '-P';
        $argv[] = (string) $port;
        $argv[] = $user . '@' . $host . ':' . $remotePath;
        $argv[] = $part;

        $started = microtime(true);
        $res = $this->cmd->run($argv, self::PULL_TIMEOUT);
        $duration = (int) round((microtime(true) - $started) * 1000);
        if ($res->exitCode !== 0 || !is_file($part)) {
            throw new TaskRejectedException(
                "remote pull fail ({$user}@{$host}:{$remotePath}): " . $this->cleanError($res)
            );
        }
        $bytes = filesize($part);
        if (!is_int($bytes) || $bytes < 20) {
            throw new TaskRejectedException('remote pull se koi archive nahi aaya (file khaali ya 20 byte se chhoti)');
        }
        $sha = hash_file('sha256', $part);
        if (!is_string($sha)) {
            throw new TaskRejectedException('downloaded archive ka checksum nahi ban paya');
        }
        $expectedSha = strtolower(trim((string) ($spec['sha256'] ?? '')));
        if ($expectedSha !== '') {
            if (preg_match('/^[a-f0-9]{64}$/', $expectedSha) !== 1) {
                throw new TaskRejectedException('diya gaya sha256 galat hai (64 hex chars chahiye)');
            }
            if (!hash_equals($expectedSha, $sha)) {
                throw new TaskRejectedException(
                    "checksum mismatch: remote file {$sha} hai, admin ne {$expectedSha} bola — file hat gayi (import nahi hoga)"
                );
            }
        }

        if (!@rename($part, $final)) {
            throw new TaskRejectedException("downloaded file ko {$destName} banaya nahi ja saka");
        }
        @chmod($final, 0640);

        return [
            'path' => $final,
            'name' => $destName,
            'bytes' => $bytes,
            'sha256' => $sha,
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'fingerprint' => $probed['fingerprint'],
            'fingerprints' => $probed['fingerprints'],
            'key_type' => $probed['key_type'],
            'auth' => $auth,
            'duration_ms' => $duration,
        ];
    }

    // ------------------------------------------------------------------ helpers --

    /** @param array<string, mixed> $spec */
    private function keyFile(array $spec): string
    {
        $pem = trim((string) ($spec['private_key'] ?? ''));
        if ($pem !== '') {
            if (!str_contains($pem, 'PRIVATE KEY')) {
                throw new TaskRejectedException('private_key PEM jaisa nahi lagta (usme "PRIVATE KEY" hona chahiye)');
            }
            if (strlen($pem) > 65536) {
                throw new TaskRejectedException('private_key bahut bada hai');
            }
            $file = $this->tempFile(0600);
            file_put_contents($file, $pem . "\n");

            return $file;
        }

        $path = trim((string) ($spec['key_path'] ?? ''));
        if ($path === '') {
            throw new TaskRejectedException('key auth ke liye private_key (PEM) ya key_path do');
        }
        if (!str_starts_with($path, '/') || preg_match('#^/[A-Za-z0-9._/-]+$#', $path) !== 1 || str_contains($path, '..')) {
            throw new TaskRejectedException("key_path '{$path}' galat hai (absolute, '..' ke bina)");
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new TaskRejectedException("key_path {$path} nahi mila ya padha nahi ja sakta (agent root hai, permission dekho)");
        }
        $copy = $this->tempFile(0600);
        if (@copy($path, $copy) !== true) {
            throw new TaskRejectedException("key_path {$path} copy nahi ho payi");
        }

        return $copy;
    }

    /** Delete one temp file right away (probe() does not need it afterwards). */
    private function forget(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
        $this->tempFiles = array_values(array_filter($this->tempFiles, static fn (string $f): bool => $f !== $file));
    }

    private function tempFile(int $mode): string
    {
        $file = tempnam(sys_get_temp_dir(), 'acprp');
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
            if (is_file($file)) {
                // overwrite before unlink: a private key should not survive on disk
                $size = filesize($file);
                if (is_int($size) && $size > 0 && $size < 1_048_576) {
                    @file_put_contents($file, str_repeat("\0", $size));
                }
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /** scp/ssh errors can echo the remote banner — keep them short and quote-free. */
    private function cleanError(CommandResult $res): string
    {
        $msg = trim($res->stderr !== '' ? $res->stderr : $res->stdout);
        $msg = preg_replace('/[^\x20-\x7E]+/', ' ', $msg) ?? '';
        $msg = str_replace(['"', "'", '`'], '', $msg);
        $msg = preg_replace('/\s+/', ' ', $msg) ?? '';

        return substr($msg, 0, 300) === '' ? "exit {$res->exitCode}" : substr($msg, 0, 300);
    }
}
