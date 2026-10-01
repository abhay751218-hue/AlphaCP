<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\MysqlDatabase;
use App\Models\PhpmyadminSetting;

final class DatabaseProvisioner
{
    public static function enqueue(Account $account): int
    {
        $rows = $account->mysqlDatabases()->orderBy('id')->get()->map(static fn (MysqlDatabase $row): array => [
            'name' => $row->name,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'db.set', [
            'username' => $account->username,
            'databases' => $rows,
        ]);
    }

    public static function limitReached(Account $account): bool
    {
        $max = (int) ($account->package?->MAXSQL ?? -1);
        if ($max < 0) {
            return false;
        }

        return $account->mysqlDatabases()->count() >= $max;
    }

    public static function enqueuePhpmyadmin(Account $account): int
    {
        $row = PhpmyadminSetting::query()->where('account_id', $account->id)->first();

        return AccountProvisioner::enqueue($account, 'db.phpmyadmin', [
            'username' => $account->username,
            'enabled' => (bool) ($row?->enabled ?? false),
        ]);
    }
}
