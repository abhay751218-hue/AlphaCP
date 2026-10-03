<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\DnsDynamicHost;

final class DynamicDnsProvisioner
{
    public static function enqueue(Account $account): int
    {
        $rows = $account->dynamicDnsHosts()->orderBy('id')->get()->map(static fn (DnsDynamicHost $row): array => [
            'domain' => $row->domain,
            'name' => $row->name,
            'token' => $row->token,
            'ip' => (string) $row->ip,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'dns.dynamic', [
            'username' => $account->username,
            'hosts' => $rows,
        ]);
    }

    public static function limitReached(Account $account): bool
    {
        return $account->dynamicDnsHosts()->count() >= 20;
    }
}
