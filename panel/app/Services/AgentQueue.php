<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The ONLY way the panel talks to privileged work.
 *
 * Panel → `tasks` table → paneld (root) executes → result written back.
 * The panel itself never runs a privileged command (ADR-0002).
 */
final class AgentQueue
{
    public static function enqueue(string $type, array $payload = [], string $source = 'panel', int $priority = 100): int
    {
        return (int) DB::table('tasks')->insertGetId([
            'server_id'    => self::serverId(),
            'type'         => $type,
            'safety'       => 'readonly', // slice-1 tasks are all read-only; mutating tasks arrive with their modules
            'payload'      => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'priority'     => $priority,
            'status'       => 'queued',
            'requested_src'=> $source,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Latest successful result of a task type, if it finished within $freshSeconds. */
    public static function latestWithin(string $type, int $freshSeconds = 30): ?array
    {
        $row = DB::table('tasks')
            ->where('server_id', self::serverId())
            ->where('type', $type)
            ->where('status', 'success')
            ->where('finished_at', '>=', now()->subSeconds($freshSeconds))
            ->orderByDesc('id')
            ->first();

        if ($row === null || $row->result === null) {
            return null;
        }
        return json_decode((string) $row->result, true) ?: null;
    }

    /** Fresh-or-enqueue helper: reuse a recent result, else queue and wait a moment. */
    public static function fetch(string $type, array $payload = [], int $waitSeconds = 3, int $freshSeconds = 30): array
    {
        $cached = self::latestWithin($type, $freshSeconds);
        if ($cached !== null) {
            return ['status' => 'success', 'result' => $cached, 'cached' => true, 'task_id' => null];
        }

        $id = self::enqueue($type, $payload);

        for ($i = 0; $i < $waitSeconds * 4; $i++) {
            usleep(250_000);
            $row = DB::table('tasks')->where('id', $id)->first(['status', 'result', 'error']);
            if ($row === null) {
                break;
            }
            if (in_array($row->status, ['success', 'failed', 'cancelled'], true)) {
                return [
                    'status'  => $row->status,
                    'result'  => $row->result ? json_decode((string) $row->result, true) : null,
                    'error'   => $row->error,
                    'cached'  => false,
                    'task_id' => $id,
                ];
            }
        }

        // agent busy or stopped — the UI shows "pending" instead of hanging
        return ['status' => 'pending', 'result' => null, 'task_id' => $id, 'cached' => false];
    }

    /** @return array{queued:int,running:int,success:int,failed:int,last:?object} */
    public static function stats(): array
    {
        $counts = ['queued' => 0, 'running' => 0, 'success' => 0, 'failed' => 0];
        $rows = DB::table('tasks')->where('server_id', self::serverId())
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->get();
        foreach ($rows as $row) {
            if (array_key_exists($row->status, $counts)) {
                $counts[$row->status] = (int) $row->n;
            }
        }
        $counts['last'] = DB::table('tasks')->where('server_id', self::serverId())
            ->orderByDesc('id')->first(['id', 'type', 'status', 'duration_ms', 'finished_at']);

        return $counts;
    }

    public static function serverId(): int
    {
        return (int) (getenv('ACP_SERVER_ID') ?: 1);
    }
}
