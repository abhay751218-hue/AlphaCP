<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The panel NEVER does privileged work itself.
 *
 * It enqueues a task row; the root agent (paneld) picks it up, validates the
 * payload against its allowlist and executes it. This class is the panel side
 * of that contract (docs/01-architecture.md, ADR-0002).
 */
final class Paneld
{
    /** @return array<string, array<string, mixed>> */
    public static function registry(): array
    {
        $file = config('acp.home') . '/agent/config/tasks.php';

        // The @ matters: PHP raised a warning → ErrorException → HTTP 500 on the
        // dashboard when the pool's open_basedir did not include the agent's
        // config dir. A missing/unreadable registry must degrade to "no tasks",
        // never take the panel down.
        if (! @is_file($file) || ! @is_readable($file)) {
            return [];
        }

        $registry = require $file;

        return is_array($registry) ? $registry : [];
    }

    /** @return list<string> */
    public static function taskTypes(): array
    {
        return array_keys(self::registry());
    }

    /** @param array<string, mixed> $payload */
    public static function enqueue(string $type, array $payload = [], string $source = 'panel', ?int $accountId = null): int
    {
        $registry = self::registry();
        if (!isset($registry[$type])) {
            throw new \InvalidArgumentException("Unknown agent task: {$type}");
        }

        $safety = (string) ($registry[$type]['safety'] ?? 'readonly');

        $id = DB::table('tasks')->insertGetId([
            'server_id'     => Panel::serverId(),
            'type'          => $type,
            'safety'        => $safety,
            'payload'       => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'status'        => 'queued',
            'account_id'    => $accountId,
            'requested_by'  => auth()->id(),
            'requested_src' => $source,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        Audit::log('task.queued', 'info', 'task', $id, ['type' => $type, 'safety' => $safety]);

        return $id;
    }

    /**
     * Queue + wait for the result (dashboard/system pages use this).
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public static function run(string $type, array $payload = [], int $waitSeconds = 12): ?array
    {
        $id = self::enqueue($type, $payload, 'panel-sync');

        $deadline = microtime(true) + $waitSeconds;
        while (microtime(true) < $deadline) {
            $task = DB::table('tasks')->where('id', $id)->first();
            if ($task && in_array($task->status, ['success', 'failed', 'cancelled'], true)) {
                if ($task->status !== 'success') {
                    return null;
                }
                $decoded = json_decode((string) $task->result, true);
                return is_array($decoded) ? $decoded : null;
            }
            usleep(300_000);
        }

        return null;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function recentTasks(int $limit = 12)
    {
        return DB::table('tasks')
            ->where('server_id', Panel::serverId())
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function taskLogs(int $taskId, int $limit = 200)
    {
        return DB::table('task_logs')->where('task_id', $taskId)->orderBy('id')->limit($limit)->get();
    }
}
