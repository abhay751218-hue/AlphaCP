<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tests;

use Alphacp\Agent\CommandExecutor;
use Alphacp\Agent\CommandResult;

/**
 * In-memory CommandExecutor for account-handler tests. Never touches the OS.
 */
final class FakeCommandExecutor implements CommandExecutor
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var array<string, string> username => gecos */
    public array $users = [];

    /** @var array<string, true> */
    public array $locked = [];

    /** @var array<string, string> username => login shell path */
    public array $shells = [];

    public ?string $crontabBody = null;

    /** @var list<string>|null overrides the plain `tar --list` output */
    public ?array $tarListLines = null;

    /** @var list<string>|null overrides the verbose `tar --list --verbose` output */
    public ?array $tarVerboseLines = null;

    /** @var list<string>|null files materialised on `tar --extract` (relative to --directory) */
    public ?array $tarExtractPaths = null;

    /** @var array<string, list<string>> member name => plain `tar --list <member>` output */
    public array $tarMemberList = [];

    /** @var array<string, list<string>> member name => verbose `tar --list --verbose <member>` output */
    public array $tarMemberVerbose = [];

    /** @var list<string>|null `tar --list` output when the archive is a nested homedir.tar */
    public ?array $tarNestedList = null;

    /** @var list<string>|null verbose output when the archive is a nested homedir.tar */
    public ?array $tarNestedVerbose = null;

    /** @var list<string>|null files materialised when a nested homedir.tar is extracted */
    public ?array $tarNestedExtractPaths = null;

    public ?string $failWhenContains = null;

    // ---- S10 remote pull (backup.pull) ----
    /** host key pubkey line returned by the fake `ssh-keyscan` */
    public string $hostKeyPubkey = 'old.example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
    /** fingerprint returned by the fake `ssh-keygen -l -E sha256` */
    public string $hostKeyFingerprint = 'SHA256:8Ph7mQ0FakeFingerprintAAAAAAAAAAAAAAAAAAAAAAA';
    /** when set, the SECOND keyscan reports this fingerprint (MITM / reinstall) */
    public ?string $hostKeySecondFingerprint = null;
    public int $keyscanCalls = 0;
    /**
     * Multiple host keys, exactly like a real server (ed25519 + ecdsa + rsa).
     * Each entry: ['pubkey' => string, 'fingerprint' => string, 'type' => string].
     * Empty = purane single-key behaviour (hostKeyPubkey/hostKeyFingerprint).
     */
    public array $hostKeys = [];
    /** bytes the fake `scp` writes into the destination file */
    public ?string $scpContent = 'fake-cpmove-archive-bytes-0123456789';
    public bool $scpFails = false;
    public bool $sshpassInstalled = true;
    /** last scp argv (sshpass prefix stripped) */
    public ?array $scpArgv = null;
    /** '-f <file>' value seen by sshpass on the last call */
    public ?string $sshpassFile = null;

    // ---- S10 remote backup destinations (backup.destination) ----
    public int $sshCalls = 0;
    /** last ssh argv (sshpass prefix stripped) */
    public ?array $sshArgv = null;
    /** stdout the fake `ssh` returns (test -> 'ACP-OK', push -> '<sha>  <path>') */
    public string $sshStdout = '';
    public string $sshStderr = '';
    public bool $sshFails = false;

    // ---- S9 BIND9 (dns.bind) ----
    /** `named-checkconf` fails when set (bad managed options block) */
    public bool $bindCheckconfFails = false;
    /** `named-checkzone` fails when set — the gate that must stop every bad zone */
    public bool $bindCheckzoneFails = false;
    public int $namedCheckzoneCalls = 0;
    /** @var list<string>|null last `named-checkzone` argv (zone name + file) */
    public ?array $namedCheckzoneArgv = null;
    public int $rndcCalls = 0;
    /** @var list<string>|null last `rndc` argv */
    public ?array $rndcArgv = null;
    /** @var list<list<string>> har `rndc` call ka argv (reconfig/reload ka farq dikhane ke liye) */
    public array $rndcArgvs = [];
    /** stdout the fake `dig` returns (the real SOA/NS answer we verify against) */
    public string $digStdout = '';
    /** @var list<string>|null last `dig` argv */
    public ?array $digArgv = null;
    /** stdout of `hostname -I` — the server's own IPs for listen-on */
    public string $hostnameI = '';

    /** @var list<string> databases that exist in the fake MariaDB */
    public array $mysqlDatabases = [];

    /** @var array<string, list<string>> 'user@host' => granted databases */
    public array $mysqlUsers = [];

    /** @var list<list<string>> rows returned by the next SQL that is a SELECT */
    public ?array $mysqlRows = null;

    /** @var list<string> every SQL script the agent sent to the client */
    public array $mysqlSql = [];

    /** @var list<string> the client's argv for each SQL call */
    public array $mysqlArgv = [];

    public ?string $mysqlFailWhenContains = null;

    /** @var list<array{argv: list<string>, file: string, contents: string}> stdinFile calls (db.restore) */
    public array $stdinFiles = [];

    public function run(array $argv, ?int $timeout = null, ?string $stdin = null, ?string $stdinFile = null): CommandResult
    {
        if ($stdinFile !== null) {
            $contents = @file_get_contents($stdinFile);
            $this->stdinFiles[] = ['argv' => $argv, 'file' => $stdinFile, 'contents' => is_string($contents) ? $contents : ''];
            $stdin = is_string($contents) ? $contents : '';
        }
        $this->calls[] = $argv;
        $line = implode(' ', $argv);
        if ($this->failWhenContains !== null && str_contains($line, $this->failWhenContains)) {
            return new CommandResult($argv, 1, '', 'injected failure: ' . $this->failWhenContains, 1);
        }

        $bin = basename((string) ($argv[0] ?? ''));
        return match ($bin) {
            'getent' => $this->getent($argv),
            'useradd' => $this->useradd($argv),
            'userdel' => $this->userdel($argv),
            'usermod' => $this->usermod($argv),
            'setquota', 'systemctl' => new CommandResult($argv, 0, "fake {$bin} ok\n", '', 1),
            'crontab' => $this->handleCrontab($argv, $stdin),
            'certbot' => $this->handleCertbot($argv),
            'tar' => $this->handleTar($argv),
            'mariadb', 'mysql' => $this->handleMysql($argv, $stdin),
            'ssh-keyscan' => $this->handleKeyscan($argv),
            'ssh-keygen' => $this->handleKeygen($argv),
            'scp' => $this->handleScp($argv),
            'ssh' => $this->handleSsh($argv),
            'sshpass' => $this->handleSshpass($argv),
            'named-checkconf' => $this->bindCheckconfFails
                ? new CommandResult($argv, 1, "", "/etc/bind/named.conf.options:9: missing ';' before '}'", 1)
                : new CommandResult($argv, 0, '', '', 1),
            'named-checkzone' => $this->handleNamedCheckzone($argv),
            'rndc' => $this->handleRndc($argv),
            'dig' => $this->handleDig($argv),
            'hostname' => new CommandResult($argv, 0, $this->hostnameI, '', 1),
            default => new CommandResult($argv, 0, '', '', 1),
        };
    }

    /** @param list<string> $argv */
    private function getent(array $argv): CommandResult
    {
        $user = $argv[2] ?? '';
        if (($argv[1] ?? '') === 'passwd' && isset($this->users[$user])) {
            $gecos = $this->users[$user];
            $shell = $this->shells[$user] ?? '/usr/sbin/nologin';
            $line = "{$user}:x:1500:1500:{$gecos}:/home/{$user}:{$shell}\n";
            return new CommandResult($argv, 0, $line, '', 1);
        }
        return new CommandResult($argv, 2, '', 'not found', 1);
    }

    /** @param list<string> $argv */
    private function useradd(array $argv): CommandResult
    {
        $user = $argv[array_key_last($argv)] ?? '';
        $gecos = 'AlphaCP unknown';
        foreach ($argv as $i => $arg) {
            if ($arg === '-c' && isset($argv[$i + 1])) {
                $gecos = $argv[$i + 1];
            }
        }
        if (isset($this->users[$user])) {
            return new CommandResult($argv, 9, '', 'user exists', 1);
        }
        $this->users[$user] = $gecos;
        return new CommandResult($argv, 0, '', '', 1);
    }

    /** @param list<string> $argv */
    private function userdel(array $argv): CommandResult
    {
        $user = $argv[array_key_last($argv)] ?? '';
        if (!isset($this->users[$user])) {
            return new CommandResult($argv, 6, '', 'no such user', 1);
        }
        unset($this->users[$user], $this->locked[$user]);
        return new CommandResult($argv, 0, '', '', 1);
    }

    /** @param list<string> $argv */
    private function usermod(array $argv): CommandResult
    {
        $user = $argv[array_key_last($argv)] ?? '';
        if (!isset($this->users[$user])) {
            return new CommandResult($argv, 6, '', 'no such user', 1);
        }
        if (in_array('-L', $argv, true)) {
            $this->locked[$user] = true;
        }
        if (in_array('-U', $argv, true)) {
            unset($this->locked[$user]);
        }
        foreach ($argv as $i => $arg) {
            if ($arg === '-s' && isset($argv[$i + 1])) {
                $this->shells[$user] = $argv[$i + 1];
            }
        }
        return new CommandResult($argv, 0, '', '', 1);
    }

    /** @param list<string> $argv */
    private function handleTar(array $argv): CommandResult
    {
        $fileIndex = array_search('--file', $argv, true);
        $path = is_int($fileIndex) ? (string) ($argv[$fileIndex + 1] ?? '') : '';
        if ($path === '') {
            return new CommandResult($argv, 2, '', 'tar: missing --file', 1);
        }
        $nested = str_ends_with($path, '/homedir.tar') || str_ends_with($path, '/homedir');
        $member = null;
        $dashIndex = array_search('--', $argv, true);
        if (is_int($dashIndex) && isset($argv[$dashIndex + 1])) {
            $member = (string) $argv[$dashIndex + 1];
        }
        if (in_array('--create', $argv, true)) {
            if (@file_put_contents($path, "fake-gzip-tar-archive\n") === false) {
                return new CommandResult($argv, 2, '', 'tar: cannot create archive', 1);
            }
            return new CommandResult($argv, 0, '', '', 1);
        }
        if (in_array('--list', $argv, true) && is_file($path)) {
            if (in_array('--verbose', $argv, true)) {
                if ($member !== null && isset($this->tarMemberVerbose[$member])) {
                    $lines = $this->tarMemberVerbose[$member];
                } elseif ($nested) {
                    $lines = $this->tarNestedVerbose ?? [
                        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 ./public_html/',
                        '-rw-r--r-- 1500/1500 14 2026-10-03 16:00 ./public_html/index.php',
                    ];
                } else {
                    $lines = $this->tarVerboseLines ?? [
                        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 alicehost/',
                        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 alicehost/public_html/',
                        '-rw-r--r-- 1500/1500 21 2026-10-03 16:00 alicehost/public_html/index.php',
                    ];
                }
                return new CommandResult($argv, 0, implode("\n", $lines) . "\n", '', 1);
            }
            if ($member !== null && isset($this->tarMemberList[$member])) {
                $lines = $this->tarMemberList[$member];
            } elseif ($nested) {
                $lines = $this->tarNestedList ?? ['./public_html/', './public_html/index.php'];
            } else {
                $lines = $this->tarListLines ?? ['alicehost/', 'alicehost/public_html/', 'alicehost/public_html/index.php'];
            }
            return new CommandResult($argv, 0, implode("\n", $lines) . "\n", '', 1);
        }
        if (in_array('--extract', $argv, true)) {
            $dirIndex = array_search('--directory', $argv, true);
            $target = is_int($dirIndex) ? (string) ($argv[$dirIndex + 1] ?? '') : '';
            if ($target === '' || !is_dir($target)) {
                return new CommandResult($argv, 2, '', 'tar: cannot chdir', 1);
            }
            if ($nested) {
                $paths = $this->tarNestedExtractPaths ?? ['./public_html/index.php' => 'nested import'];
            } else {
                $paths = $this->tarExtractPaths ?? [
                    'alicehost/public_html/index.php' => '<?php echo "restored";',
                    'alicehost/public_html/restored.txt' => 'restored file',
                ];
            }
            foreach ($paths as $key => $rel) {
                $file = $target . '/' . (is_int($key) ? $rel : $key);
                if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true) && !is_dir(dirname($file))) {
                    return new CommandResult($argv, 2, '', 'tar: mkdir failed', 1);
                }
                if (@file_put_contents($file, is_int($key) ? 'restored' : $rel) === false) {
                    return new CommandResult($argv, 2, '', 'tar: write failed', 1);
                }
            }
            return new CommandResult($argv, 0, '', '', 1);
        }
        return new CommandResult($argv, 2, '', 'tar: archive missing', 1);
    }

    /** @param list<string> $argv */
    private function handleCertbot(array $argv): CommandResult
    {
        $config = '';
        $domain = '';
        foreach ($argv as $i => $arg) {
            if ($arg === '--config-dir' && isset($argv[$i + 1])) {
                $config = (string) $argv[$i + 1];
            }
            if ($arg === '-d' && isset($argv[$i + 1])) {
                $domain = (string) $argv[$i + 1];
            }
        }
        if ($config === '' || $domain === '') {
            return new CommandResult($argv, 1, '', 'certbot: missing --config-dir or -d', 1);
        }
        $live = $config . '/live/' . $domain;
        if (!is_dir($live) && !@mkdir($live, 0700, true) && !is_dir($live)) {
            return new CommandResult($argv, 1, '', 'certbot: mkdir live failed', 1);
        }
        file_put_contents($live . '/fullchain.pem', "-----BEGIN CERTIFICATE-----\nLE-fake\n-----END CERTIFICATE-----\n");
        file_put_contents($live . '/privkey.pem', "-----BEGIN PRIVATE KEY-----\nLE-fake\n-----END PRIVATE KEY-----\n");
        return new CommandResult($argv, 0, "Successfully received certificate.\n", '', 1);
    }

    /**
     * A tiny MariaDB client: understands the small SQL surface the db.* tasks
     * send, and records the SQL + argv so tests can prove no password ever
     * reaches the command line.
     *
     * @param list<string> $argv
     */
    private function handleMysql(array $argv, ?string $stdin): CommandResult
    {
        $this->mysqlArgv[] = $argv;
        $sql = (string) ($stdin ?? '');
        $this->mysqlSql[] = $sql;

        foreach (explode(";", $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            if ($this->mysqlFailWhenContains !== null && str_contains($statement, $this->mysqlFailWhenContains)) {
                return new CommandResult($argv, 1, '', "ERROR 1064 (42000) at line 1: You have an error in your SQL syntax near '…'", 1);
            }
            if (preg_match('/^CREATE DATABASE (?:IF NOT EXISTS )?`([a-z0-9_]+)`/i', $statement, $m) === 1) {
                $this->mysqlDatabases[] = $m[1];
                $this->mysqlDatabases = array_values(array_unique($this->mysqlDatabases));
                continue;
            }
            if (preg_match('/^DROP DATABASE `([a-z0-9_]+)`/i', $statement, $m) === 1) {
                $this->mysqlDatabases = array_values(array_diff($this->mysqlDatabases, [$m[1]]));
                continue;
            }
            if (preg_match('/^CREATE USER \'([a-z0-9_]+)\'@\'([^\']+)\'/i', $statement, $m) === 1) {
                $this->mysqlUsers[$m[1] . '@' . $m[2]] ??= [];
                continue;
            }
            if (preg_match('/^DROP USER \'([a-z0-9_]+)\'@\'([^\']+)\'/i', $statement, $m) === 1) {
                unset($this->mysqlUsers[$m[1] . '@' . $m[2]]);
                continue;
            }
            if (preg_match('/^GRANT ALL PRIVILEGES ON `([a-z0-9_]+)`\.\* TO \'([a-z0-9_]+)\'@\'([^\']+)\'/i', $statement, $m) === 1) {
                $key = $m[2] . '@' . $m[3];
                $this->mysqlUsers[$key] ??= [];
                if (!in_array($m[1], $this->mysqlUsers[$key], true)) {
                    $this->mysqlUsers[$key][] = $m[1];
                }
                continue;
            }
            if (preg_match('/^REVOKE ALL PRIVILEGES ON `([a-z0-9_]+)`\.\* FROM \'([a-z0-9_]+)\'@\'([^\']+)\'/i', $statement, $m) === 1) {
                $key = $m[2] . '@' . $m[3];
                $this->mysqlUsers[$key] = array_values(array_diff($this->mysqlUsers[$key] ?? [], [$m[1]]));
                continue;
            }
            if (preg_match('/^(SHOW DATABASES|SELECT SCHEMA_NAME|SELECT User|SHOW GRANTS)/i', $statement) === 1) {
                return new CommandResult($argv, 0, $this->mysqlSelect($statement), '', 1);
            }
        }

        return new CommandResult($argv, 0, '', '', 1);
    }

    private function mysqlSelect(string $statement): string
    {
        if (str_starts_with($statement, 'SHOW DATABASES')) {
            return implode("\n", $this->mysqlDatabases) . "\n";
        }
        if (str_starts_with($statement, 'SELECT SCHEMA_NAME')) {
            if (preg_match("/SCHEMA_NAME = '([a-z0-9_]+)'/", $statement, $m) === 1 && in_array($m[1], $this->mysqlDatabases, true)) {
                return $m[1] . "\n";
            }
            return '';
        }
        if (preg_match("/^SELECT User FROM mysql[.]user WHERE User = '([a-z0-9_]+)' AND Host = '([^']+)'/", $statement, $m) === 1) {
            return isset($this->mysqlUsers[$m[1] . '@' . $m[2]]) ? $m[1] . "\n" : '';
        }
        if (str_starts_with($statement, 'SELECT User, Host')) {
            $lines = [];
            foreach (array_keys($this->mysqlUsers) as $key) {
                [$user, $host] = explode('@', $key, 2);
                $lines[] = $user . "\t" . $host;
            }
            return $lines === [] ? '' : implode("\n", $lines) . "\n";
        }
        if (preg_match("/^SHOW GRANTS FOR '([a-z0-9_]+)'@'([^']+)'/", $statement, $m) === 1) {
            $key = $m[1] . '@' . $m[2];
            if (!isset($this->mysqlUsers[$key])) {
                return '';
            }
            $lines = ["GRANT USAGE ON *.* TO `{$m[1]}`@`{$m[2]}`"];
            foreach ($this->mysqlUsers[$key] as $database) {
                $lines[] = "GRANT ALL PRIVILEGES ON `{$database}`.* TO `{$m[1]}`@`{$m[2]}`";
            }
            return implode("\n", $lines) . "\n";
        }
        return '';
    }

    /** @param list<string> $argv */
    private function handleCrontab(array $argv, ?string $stdin): CommandResult
    {
        if (in_array('-r', $argv, true)) {
            $this->crontabBody = '';
            return new CommandResult($argv, 0, '', '', 1);
        }
        $this->crontabBody = (string) $stdin;
        return new CommandResult($argv, 0, '', '', 1);
    }

    /** @return list<string> */
    private function keyscanLines(): array
    {
        if ($this->hostKeys !== []) {
            $out = [];
            foreach ($this->hostKeys as $key) {
                $out[] = (string) $key['pubkey'];
            }

            return $out;
        }

        return [$this->hostKeyPubkey];
    }

    private function fingerprintFor(string $pubkey): string
    {
        foreach ($this->hostKeys as $key) {
            if (($key['pubkey'] ?? '') === $pubkey) {
                return (string) $key['fingerprint'];
            }
        }
        if ($this->hostKeySecondFingerprint !== null && $this->keyscanCalls >= 2) {
            return $this->hostKeySecondFingerprint;
        }

        return $this->hostKeyFingerprint;
    }

    private function keyTypeFor(string $pubkey): string
    {
        foreach ($this->hostKeys as $key) {
            if (($key['pubkey'] ?? '') === $pubkey) {
                return (string) ($key['type'] ?? 'ED25519');
            }
        }

        return 'ED25519';
    }

    /** @param list<string> $argv */
    private function handleKeyscan(array $argv): CommandResult
    {
        $this->keyscanCalls++;

        return new CommandResult($argv, 0, implode("\n", $this->keyscanLines()) . "\n", '', 1);
    }

    /** @param list<string> $argv */
    private function handleKeygen(array $argv): CommandResult
    {
        $file = '';
        $generate = false;
        foreach ($argv as $i => $a) {
            if ($a === '-f' && isset($argv[$i + 1])) {
                $file = (string) $argv[$i + 1];
            }
            if ($a === '-t') {
                $generate = true;      // ssh-keygen -t ed25519 -f <file>  => naya key banao
            }
        }
        if ($generate && $file !== '') {
            @file_put_contents($file, "-----BEGIN OPENSSH PRIVATE KEY-----\nfake-key-material\n-----END OPENSSH PRIVATE KEY-----\n");
            @chmod($file, 0600);
            @file_put_contents($file . '.pub', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIfakekeyforbackup alphacp-backup');

            return new CommandResult($argv, 0, '', '', 1);
        }
        $raw = @file_get_contents($file);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $raw) ?: [])));
        if ($lines === []) {
            $lines = ['old.example.com ssh-ed25519 AAAA'];
        }
        $out = '';
        foreach ($lines as $line) {
            $host = (string) (explode(' ', $line)[0] ?? 'old.example.com');

            $out .= sprintf(
                "256 %s %s (%s)\n",
                $this->fingerprintFor($line),
                $host,
                $this->keyTypeFor($line),
            );
        }

        return new CommandResult($argv, 0, $out, '', 1);
    }

    /** @param list<string> $argv */
    private function handleSsh(array $argv): CommandResult
    {
        $this->sshCalls++;
        $this->sshArgv = $argv;
        if ($this->sshFails) {
            return new CommandResult($argv, 1, '', $this->sshStderr !== '' ? $this->sshStderr : 'ssh: connect to host failed', 1);
        }

        return new CommandResult($argv, 0, $this->sshStdout, '', 1);
    }

    /** @param list<string> $argv */
    private function handleScp(array $argv): CommandResult
    {
        $this->scpArgv = $argv;
        if ($this->scpFails) {
            return new CommandResult($argv, 1, '', 'Permission denied (publickey).', 1);
        }
        $dest = (string) (count($argv) >= 2 ? $argv[count($argv) - 1] : '');
        if ($dest === '') {
            return new CommandResult($argv, 2, '', 'scp: no destination', 1);
        }
        // PUSH: destination door ke server par hai (user@host:/path) — local disk
        // par kuch likhne ki zaroorat nahi, bas argv record karo.
        if (str_contains($dest, '@') && str_contains($dest, ':')) {
            return new CommandResult($argv, 0, '', '', 1);
        }
        if (@file_put_contents($dest, (string) $this->scpContent) === false) {
            return new CommandResult($argv, 1, '', "scp: cannot write {$dest}", 1);
        }

        return new CommandResult($argv, 0, '', '', 1);
    }

    /** @param list<string> $argv */
    private function handleSshpass(array $argv): CommandResult
    {
        if (!$this->sshpassInstalled) {
            return new CommandResult($argv, 127, '', 'sshpass: command not found', 1);
        }
        // sshpass -f <file> (scp|ssh) ...
        $rest = $argv;
        array_shift($rest);                    // /usr/bin/sshpass
        if (($rest[0] ?? '') === '-f') {
            $this->sshpassFile = (string) ($rest[1] ?? '');
            array_shift($rest);
            array_shift($rest);
        }

        return basename((string) ($rest[0] ?? '')) === 'ssh'
            ? $this->handleSsh($rest)
            : $this->handleScp($rest);
    }

    /** @param list<string> $argv */
    private function handleNamedCheckzone(array $argv): CommandResult
    {
        $this->namedCheckzoneCalls++;
        $this->namedCheckzoneArgv = $argv;

        return $this->bindCheckzoneFails
            ? new CommandResult($argv, 1, '', 'zone example.com/IN: bad A record at line 12', 1)
            : new CommandResult($argv, 0, 'zone ' . (string) ($argv[1] ?? '') . '/IN: loaded serial 2025090100\nOK\n', '', 1);
    }

    /** @param list<string> $argv */
    private function handleRndc(array $argv): CommandResult
    {
        $this->rndcCalls++;
        $this->rndcArgv = $argv;
        $this->rndcArgvs[] = $argv;

        return new CommandResult($argv, 0, 'server reload successful\n', '', 1);
    }

    /** @param list<string> $argv */
    private function handleDig(array $argv): CommandResult
    {
        $this->digArgv = $argv;

        return new CommandResult($argv, 0, $this->digStdout, '', 1);
    }
}
