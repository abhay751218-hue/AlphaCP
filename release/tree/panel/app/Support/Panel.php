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
