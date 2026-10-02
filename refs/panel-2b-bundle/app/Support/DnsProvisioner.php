<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsTemplate;
use App\Models\NsRecord;
use App\Models\ParkedDomain;

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

    public static function enqueueTrack(Account $account, string $query, string $type): int
    {
        return AccountProvisioner::enqueue($account, 'dns.track', [
            'username' => $account->username,
            'query' => $query,
            'type' => $type,
        ]);
    }

    public static function enqueueHostname(string $hostname, string $ip): int
    {
        return Paneld::enqueue('dns.hostname', [
            'hostname' => $hostname,
            'ip' => $ip,
        ]);
    }

    public static function enqueueTemplates(): int
    {
        $rows = DnsTemplate::query()->orderBy('id')->get()->map(static fn (DnsTemplate $row): array => [
            'name' => $row->name,
            'body' => $row->body,
        ])->values()->all();

        return Paneld::enqueue('dns.templates', [
            'templates' => $rows,
        ]);
    }

    public static function enqueueNsReport(): int
    {
        $rows = NsRecord::query()->orderBy('id')->get()->map(static fn (NsRecord $row): array => [
            'domain' => $row->domain,
            'nameserver' => $row->nameserver,
        ])->values()->all();

        return Paneld::enqueue('dns.nsreport', [
            'records' => $rows,
        ]);
    }

    public static function enqueuePark(): int
    {
        $rows = ParkedDomain::query()->orderBy('id')->get()->map(static fn (ParkedDomain $row): array => [
            'domain' => $row->domain,
            'target' => $row->target,
        ])->values()->all();

        return Paneld::enqueue('dns.park', [
            'parks' => $rows,
        ]);
    }
}
