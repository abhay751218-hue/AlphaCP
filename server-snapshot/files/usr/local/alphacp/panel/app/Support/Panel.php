<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Small helpers shared by panel controllers. */
final class Panel
{
    public static function serverId(): int
    {
        return (int) config('acp.server_id', 1);
    }

    /** @return array<string, mixed>|null */
    public static function server(): ?array
    {
        $row = DB::table('servers')->where('id', self::serverId())->first();
        return $row ? (array) $row : null;
    }

    /** @return array{queued:int,running:int,success:int,failed:int} */
    public static function queueStats(): array
    {
        $rows = DB::table('tasks')
            ->selectRaw('status, COUNT(*) as n')
            ->where('server_id', self::serverId())
            ->groupBy('status')
            ->pluck('n', 'status');

        return [
            'queued'  => (int) ($rows['queued'] ?? 0),
            'running' => (int) ($rows['running'] ?? 0),
            'success' => (int) ($rows['success'] ?? 0),
            'failed'  => (int) ($rows['failed'] ?? 0),
        ];
    }

    /** @return array<string, string> */
    public static function versions(): array
    {
        return [
            'panel'     => (string) config('acp.version', '0.1.0'),
            'agent'     => (string) config('acp.agent_version', '0.1.0'),
            'php'       => PHP_VERSION,
            'framework' => app()->version(),
        ];
    }

    /**
     * cPanel ke right-hand **General Information** column ka data.
     * (docs/10-ui-parity-design.md §3 — Current User, Primary Domain, Shared IP,
     * Home Directory, Last Login IP, Last Login, Theme, Server Information.)
     *
     * @return list<array{label:string, value:string, mono?:bool, href?:string|null}>
     */
    public static function generalInfo(?\App\Models\User $user = null): array
    {
        $user ??= auth()->user();
        $server = self::server();
        $account = $user?->hostingAccount;

        $rows = [
            ['label' => 'Current User', 'value' => (string) ($user?->username ?? '—'), 'mono' => true],
            [
                'label' => 'Primary Domain',
                'value' => (string) ($account?->main_domain ?? '—'),
                'href'  => $account ? route('domains.index') : null,
            ],
            ['label' => 'Shared IP Address', 'value' => (string) ($server['public_ip'] ?? '—'), 'mono' => true],
            ['label' => 'Home Directory', 'value' => (string) ($account?->home_path ?? '—'), 'mono' => true],
            ['label' => 'Last Login IP Address', 'value' => (string) ($user?->last_login_ip ?: '—'), 'mono' => true],
            ['label' => 'Last Login', 'value' => self::ago($user?->last_login_at?->toDateTimeString()), 'mono' => false],
            ['label' => 'Theme', 'value' => 'AlphaCP ' . Theme::label(Theme::forUser($user)), 'mono' => false],
        ];

        if ($user && ModuleCatalog::modeFor($user) === 'whm') {
            $rows[] = [
                'label' => 'Server Information',
                'value' => (string) ($server['hostname'] ?? '—'),
                'href'  => route('system.index'),
                'mono'  => true,
            ];
        }

        return $rows;
    }

    /**
     * cPanel ke **Statistics** column ka data (used / limit).
     * Limits package ke cPanel-compatible keys se aate hain (MAXPOP, MAXSQL …).
     *
     * @return list<array{label:string, used:int, limit:int, unit?:string}>
     */
    public static function statistics(?\App\Models\User $user = null): array
    {
        $user ??= auth()->user();
        $account = $user?->hostingAccount;
        if (! $account) {
            return [];
        }

        $package = $account->package;
        $limit = static fn (string $key): int => (int) ($package?->{$key} ?? 0);
        $count = static function (string $model, ?callable $where = null) use ($account): int {
            if (! class_exists($model)) {
                return 0;
            }
            $query = $model::query()->where('account_id', $account->id);
            if ($where !== null) {
                $query->where($where);
            }

            try {
                return $query->count();
            } catch (\Throwable) {
                return 0; // table is step me na ho to crash na ho
            }
        };

        $unlimited = -1;

        return [
            ['label' => 'Email Accounts', 'used' => $count(\App\Models\Mailbox::class), 'limit' => $limit('MAXPOP')],
            ['label' => 'Forwarders', 'used' => $count(\App\Models\Forwarder::class), 'limit' => $limit('MAXFWD')],
            ['label' => 'Autoresponders', 'used' => $count(\App\Models\Autoresponder::class), 'limit' => $limit('MAXRESP')],
            ['label' => 'Email Filters', 'used' => $count(\App\Models\MailFilter::class), 'limit' => $unlimited],
            ['label' => 'Databases', 'used' => $count(\App\Models\MysqlDatabase::class), 'limit' => $limit('MAXSQL')],
            ['label' => 'Subdomains', 'used' => $count(\App\Models\Domain::class, static fn ($q) => $q->where('type', 'subdomain')), 'limit' => $limit('MAXSUB')],
            ['label' => 'Addon Domains', 'used' => $count(\App\Models\Domain::class, static fn ($q) => $q->where('type', 'addon')), 'limit' => $limit('MAXADDON')],
            ['label' => 'Aliases', 'used' => $count(\App\Models\Domain::class, static fn ($q) => $q->where('type', 'alias')), 'limit' => $limit('MAXPARK')],
            ['label' => 'Disk Usage', 'used' => (int) $account->disk_used_mb, 'limit' => (int) $account->quota_mb, 'unit' => 'MB'],
            ['label' => 'Bandwidth', 'used' => (int) $account->bw_used_mb, 'limit' => $limit('BWLIMIT'), 'unit' => 'MB'],
        ];
    }

    /** Human readable "2 min ago" in the panel's language (Hinglish-friendly). */
    public static function ago(?string $timestamp): string
    {
        if (!$timestamp) {
            return '-';
        }
        $diff = time() - strtotime($timestamp);
        if ($diff < 60) {
            return $diff . 's ago';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60) . 'm ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . 'h ago';
        }
        return intdiv($diff, 86400) . 'd ago';
    }
}
