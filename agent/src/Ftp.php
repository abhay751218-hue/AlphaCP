<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Pure-FTPd virtual-user management (the S6 "FTP accounts" slice).
 *
 * The panel must NEVER shell out to `pure-pw` (web FPM runs with proc_open
 * disabled — B1). All PureDB mutations happen here, root-side, through the
 * allowlisted CommandRunner:
 *  - argv is array-form only (no shell), password travels on **stdin** (two
 *    lines, exactly like interactive pure-pw) so it never reaches argv/logs,
 *  - `-m` keeps the PureDB (`pureftpd.pdb`) rebuilt after every change,
 *  - uid/gid are resolved from the *account* via getent (never trusted from
 *    the payload) and must be real (>= 1000) system ids.
 */
final class Ftp
{
    /** Where pure-pw lives across distros. */
    private const BIN_CANDIDATES = [
        '/usr/bin/pure-pw',
        '/usr/sbin/pure-pw',
        '/usr/local/bin/pure-pw',
        '/usr/local/sbin/pure-pw',
    ];

    private const TIMEOUT = 30;

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
    ) {
    }

    /** Absolute pure-pw path (first that exists; default for fake/test envs). */
    public static function binary(): string
    {
        foreach (self::BIN_CANDIDATES as $bin) {
            if (is_file($bin)) {
                return $bin;
            }
        }

        return '/usr/bin/pure-pw';
    }

    /**
     * Validate a customer FTP password (panel enforces the same window).
     * Newlines/control chars are refused — the password is fed on stdin as two
     * lines, so an embedded newline would corrupt the pure-pw prompt.
     */
    public static function password(string $password): string
    {
        $len = strlen($password);
        if ($len < 8 || $len > 72) {
            throw new TaskRejectedException('FTP password must be 8-72 characters');
        }
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($password[$i]);
            if ($ord < 32 || $ord === 127) {
                throw new TaskRejectedException('FTP password may not contain control characters');
            }
        }

        return $password;
    }

    /** Resolve the hosting account's real uid/gid (>= 1000) from getent. */
    public function ids(string $account): array
    {
        $res = $this->cmd->run(['/usr/bin/getent', 'passwd', $account], 10);
        if (!$res->ok() || trim($res->stdout) === '') {
            throw new TaskRejectedException("cannot resolve system user '{$account}'");
        }
        $parts = explode(':', trim(explode("\n", trim($res->stdout))[0]));
        $uid = (int) ($parts[2] ?? 0);
        $gid = (int) ($parts[3] ?? 0);
        if ($uid < 1000 || $gid < 1000) {
            throw new TaskRejectedException('FTP home uid/gid out of range');
        }

        return [$uid, $gid];
    }

    /** Create the Pure-FTPd virtual user `<login>` chrooted to `$home`. */
    public function addUser(string $login, string $account, string $password, string $home): void
    {
        [$uid, $gid] = $this->ids($account);
        $res = $this->cmd->run(
            [self::binary(), 'useradd', $login, '-u', (string) $uid, '-g', (string) $gid, '-d', $home, '-m'],
            self::TIMEOUT,
            $password . "\n" . $password . "\n",
        );
        if (!$res->ok()) {
            $this->log->error('pure-pw useradd failed: ' . substr(trim($res->stderr), 0, 200));

            throw new TaskRejectedException('pure-pw useradd failed');
        }
        $this->log->info("Pure-FTPd virtual user {$login} created (chroot {$home})");
    }

    /** Change a virtual user's password. */
    public function passwd(string $login, string $password): void
    {
        $res = $this->cmd->run(
            [self::binary(), 'passwd', $login, '-m'],
            self::TIMEOUT,
            $password . "\n" . $password . "\n",
        );
        if (!$res->ok()) {
            $this->log->error('pure-pw passwd failed: ' . substr(trim($res->stderr), 0, 200));

            throw new TaskRejectedException('pure-pw passwd failed');
        }
        $this->log->info("Pure-FTPd password changed for {$login}");
    }

    /** Remove a virtual user (the chroot dir's contents are left alone). */
    public function delUser(string $login): void
    {
        $res = $this->cmd->run([self::binary(), 'userdel', $login, '-m'], self::TIMEOUT);
        if (!$res->ok()) {
            $this->log->error('pure-pw userdel failed: ' . substr(trim($res->stderr), 0, 200));

            throw new TaskRejectedException('pure-pw userdel failed');
        }
        $this->log->info("Pure-FTPd virtual user {$login} removed");
    }
}
