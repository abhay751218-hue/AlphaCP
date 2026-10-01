<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\DnsRecord;

final class DnsProvisioner
{
    public static function enqueue(Account $account): int
    {
        $rows = $account->dnsRecords()->orderBy('id')->get()->map(static fn (DnsRecord $row): array => [
            'domain' => $row->domain,
            'name' => $row->name,
            'type' => $row->type,
            'value' => $row->value,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'dns.zone', [
            'username' => $account->username,
            'records' => $rows,
        ]);
    }

    public static function limitReached(Account $account): bool
    {
        return $account->dnsRecords()->count() >= 50;
    }
}
