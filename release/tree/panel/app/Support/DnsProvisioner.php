<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsTemplate;
use App\Models\NsRecord;
use App\Models\ParkedDomain;
use App\Models\DnsCleanup;
use App\Models\ZoneTtl;
use App\Models\DomainForward;
use App\Models\DnsSync;

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

    public static function enqueueCleanup(): int
    {
        $rows = DnsCleanup::query()->orderBy('id')->pluck('domain')->values()->all();

        return Paneld::enqueue('dns.cleanup', [
            'domains' => $rows,
        ]);
    }

    public static function enqueueTtl(): int
    {
        $rows = ZoneTtl::query()->orderBy('id')->get()->map(static fn (ZoneTtl $row): array => [
            'domain' => $row->domain,
            'ttl' => (int) $row->ttl,
        ])->values()->all();

        return Paneld::enqueue('dns.ttl', [
            'zones' => $rows,
        ]);
    }

    public static function enqueueForward(): int
    {
        $rows = DomainForward::query()->orderBy('id')->get()->map(static fn (DomainForward $row): array => [
            'domain' => $row->domain,
            'url' => $row->url,
            'code' => (int) $row->code,
        ])->values()->all();

        return Paneld::enqueue('dns.forward', [
            'forwards' => $rows,
        ]);
    }

    public static function enqueueSync(): int
    {
        $rows = DnsSync::query()->orderBy('id')->pluck('domain')->values()->all();

        return Paneld::enqueue('dns.sync', [
            'domains' => $rows,
        ]);
    }

    /** S9: BIND9 khud provision karo (zone dir, managed options, include line). */
    public static function enqueueBindSetup(): int
    {
        return Paneld::enqueue('dns.bind', ['action' => 'setup']);
    }

    /**
     * S9: ek domain ki ASLI zone file likho (JSON ke saath-saath).
     * `named-checkzone` gate agent par hai — yahan se bas records jate hain.
     */
    public static function enqueueBindZone(Account $account, string $domain): int
    {
        $rows = $account->dnsRecords()
            ->where('domain', $domain)
            ->orderBy('id')
            ->get()
            ->map(static fn (DnsRecord $row): array => [
                'domain' => $row->domain,
                'name' => $row->name,
                'type' => $row->type,
                'value' => $row->value,
            ])->values()->all();

        $ttl = (int) (ZoneTtl::query()->where('domain', $domain)->value('ttl') ?? 0);
        $payload = [
            'action' => 'write',
            'domain' => $domain,
            'records' => $rows,
        ];
        if ($ttl > 0) {
            $payload['ttl'] = $ttl;
        }

        return Paneld::enqueue('dns.bind', $payload, 'panel', $account->id);
    }

    /** S9: zone file + zone clause hatao (domain delete / zone delete). */
    public static function enqueueBindRemove(string $domain): int
    {
        return Paneld::enqueue('dns.bind', ['action' => 'remove', 'domain' => $domain]);
    }

    /** S9: `dig @127.0.0.1` se asli jawab (Track DNS jaisa). */
    public static function enqueueBindVerify(string $domain): int
    {
        return Paneld::enqueue('dns.bind', ['action' => 'verify', 'domain' => $domain]);
    }

    /**
     * S9: poore server ke zones zone.json se dobara likho — cPanel ka
     * "Synchronize DNS Records". Ek hi task, har account ke liye alag nahi.
     */
    public static function enqueueBindSync(): int
    {
        return Paneld::enqueue('dns.bind', ['action' => 'sync']);
    }

    public static function enqueueNameserver(string $software, string $ns1, string $ns2): int
    {
        return Paneld::enqueue('dns.nameserver', [
            'software' => $software,
            'ns1' => $ns1,
            'ns2' => $ns2,
        ]);
    }
}
