<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;

/**
 * cPanel-style FTP Accounts (Pure-FTPd) — PANEL SIDE.
 *
 * The panel NEVER shells out to `pure-pw`: it needs root, and the web FPM pool
 * runs with `proc_open` disabled (audit B1 — this used to HTTP-500 on live).
 * All PureDB mutations are enqueued as root-agent tasks (`ftp.add`,
 * `ftp.passwd`, `ftp.del`); this class only reports capability (from the agent
 * registry, which lives inside open_basedir) and derives the cPanel-style
 * virtual-login / chroot-home names.
 */
final class Ftp
{
    /** FTP is available iff the root agent exposes the `ftp.*` tasks. */
    public static function enabled(): bool
    {
        return in_array('ftp.add', Paneld::taskTypes(), true);
    }

    /** `<account>_<suffix>` — the Pure-FTPd virtual login (cPanel style). */
    public static function loginFor(Account $account, string $suffix): string
    {
        return strtolower($account->username) . '_' . strtolower($suffix);
    }

    /** `<account-home>/ftp/<suffix>` — the chroot home for the virtual login. */
    public static function homeFor(Account $account, string $suffix): string
    {
        return rtrim($account->home_path, '/') . '/ftp/' . strtolower($suffix);
    }
}
