<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\MysqlDatabase;
use App\Models\MysqlRemoteHost;
use App\Models\MysqlUser;
use App\Models\PhpmyadminSetting;

/**
 * Panel side of the real MariaDB tasks (S8, panel 0.70.0).
 *
 * The panel books the customer's intent (database rows, user rows, grant rows)
 * and queues one agent task per action. The root agent creates/drops the actual
 * MariaDB objects — the panel never connects to MariaDB itself and never sees a
 * password after it has been shown once.
 */
final class DatabaseProvisioner
{
    /** Create the real MariaDB database <account>_<name>. */
    public static function enqueueCreate(Account $account, MysqlDatabase $database, string $source = 'panel'): int
    {
        return AccountProvisioner::enqueue($account, 'db.create', [
            'username' => $account->username,
            'name'     => $database->name,
        ], $source);
    }

    /** Drop the database + revoke every account user's privileges on it. */
    public static function enqueueDrop(Account $account, string $name, string $source = 'panel'): int
    {
        return AccountProvisioner::enqueue($account, 'db.drop', [
            'username' => $account->username,
            'name'     => $name,
            '_confirm' => 'db.drop',
        ], $source);
    }

    /**
     * Create the MariaDB user and grant it the listed databases.
     *
     * @param  list<MysqlDatabase> $databases
     */
    public static function enqueueUserCreate(Account $account, MysqlUser $user, string $password, array $databases, string $source = 'panel'): int
    {
        return AccountProvisioner::enqueue($account, 'db.user.create', [
            'username'  => $account->username,
            'user'      => $user->name,
            'host'      => $user->host,
            'password'  => $password,
            'databases' => array_map(static fn (MysqlDatabase $database): string => $database->name, $databases),
        ], $source);
    }

    public static function enqueueUserGrant(Account $account, MysqlUser $user, MysqlDatabase $database, string $source = 'panel'): int
    {
        return AccountProvisioner::enqueue($account, 'db.user.grant', [
            'username' => $account->username,
            'user'     => $user->name,
            'host'     => $user->host,
            'database' => $database->name,
        ], $source);
    }

    /** cPanel "Change Password" for a database user (new password, shown once). */
    public static function enqueueUserPassword(Account $account, MysqlUser $user, string $password, string $source = 'panel'): int
    {
        return AccountProvisioner::enqueue($account, 'db.user.password', [
            'username' => $account->username,
            'user'     => $user->name,
            'host'     => $user->host,
            'password' => $password,
        ], $source);
    }

    public static function enqueueUserDrop(Account $account, MysqlUser $user, string $source = 'panel'): int
    {
        return AccountProvisioner::enqueue($account, 'db.user.drop', [
            'username' => $account->username,
            'user'     => $user->name,
            'host'     => $user->host,
            '_confirm' => 'db.user.drop',
        ], $source);
    }

    /** Read-only: what the server really holds for this account. */
    public static function enqueueList(Account $account, string $source = 'panel'): int
    {
        return AccountProvisioner::enqueue($account, 'db.list', [
            'username' => $account->username,
        ], $source);
    }

    public static function limitReached(Account $account): bool
    {
        $max = (int) ($account->package?->MAXSQL ?? -1);
        if ($max < 0) {
            return false;
        }

        return $account->mysqlDatabases()->count() >= $max;
    }

    public static function userLimitReached(Account $account): bool
    {
        $max = (int) ($account->package?->MAXSQL ?? -1);
        if ($max < 0) {
            return false;
        }

        return $account->mysqlUsers()->count() >= $max;
    }

    public static function enqueuePhpmyadmin(Account $account): int
    {
        $row = PhpmyadminSetting::query()->where('account_id', $account->id)->first();

        return AccountProvisioner::enqueue($account, 'db.phpmyadmin', [
            'username' => $account->username,
            'enabled' => (bool) ($row?->enabled ?? false),
        ]);
    }

    public static function enqueueRemote(Account $account): int
    {
        $rows = $account->remoteHosts()->orderBy('id')->get()->map(static fn (MysqlRemoteHost $row): array => [
            'host' => $row->host,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'db.remote', [
            'username' => $account->username,
            'hosts' => $rows,
        ]);
    }

    public static function remoteLimitReached(Account $account): bool
    {
        return $account->remoteHosts()->count() >= 50;
    }
}
