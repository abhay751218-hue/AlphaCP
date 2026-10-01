<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\MysqlDatabase;

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
}
