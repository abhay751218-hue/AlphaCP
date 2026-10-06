<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * cPanel-style FTP Accounts backed by Pure-FTPd (pure-pw / PureDB).
 *
 * System calls are made through Laravel's Process facade so tests can
 * Process::fake() them. On the server, the portable installer ensures
 * pure-ftpd + pure-pw exist and the PureDB is wired in.
 */
final class Ftp
{
    /** pure-ftpd binary path if present. */
    public static function binary(): ?string
    {
        foreach (['/usr/sbin/pure-ftpd', '/usr/bin/pure-ftpd'] as $b) {
            if (is_file($b)) {
                return $b;
            }
        }

        return null;
    }

    public static function enabled(): bool
    {
        return self::binary() !== null;
    }

    /** Create a Pure-FTPd virtual user (chroot to its home). */
    public static function addUser(string $user, string $password, string $home, int $uid = 1000, int $gid = 1000): void
    {
        Process::input($password . "\n" . $password . "\n")
            ->run(['pure-pw', 'useradd', $user, '-u', (string) $uid, '-g', (string) $gid, '-d', $home, '-m']);
    }

    public static function delUser(string $user): void
    {
        Process::run(['pure-pw', 'userdel', $user, '-m']);
    }

    public static function passwd(string $user, string $password): void
    {
        Process::input($password . "\n" . $password . "\n")
            ->run(['pure-pw', 'passwd', $user, '-m']);
    }
}
