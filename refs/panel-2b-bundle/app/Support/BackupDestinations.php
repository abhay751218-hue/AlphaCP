<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BackupDestinationPush;
use Illuminate\Support\Facades\DB;

/**
 * S10 remote destinations — the archive ledger.
 *
 * Everything here is a read of the task table plus one small ledger table
 * (`backup_destination_pushes`). No shell, no tar, no path building from user
 * input: an archive is only offered when the agent already reported it as a
 * successful `backup.archive` AND the file is really there under our own backup
 * store. That keeps "push" from ever becoming "upload /etc/shadow".
 */
final class BackupDestinations
{
    /**
     * Completed home archives that still exist on disk.
     *
     * @return list<array{username:string,archive_id:string,file:string,path:string,size:?int}>
     */
    public static function pushableArchives(int $limit = 25): array
    {
        $tasks = DB::table('tasks')
            ->where('server_id', Panel::serverId())
            ->where('type', 'backup.archive')
            ->where('status', 'success')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $home = rtrim((string) config('acp.home'), '/');
        $out = [];
        $seen = [];
        foreach ($tasks as $task) {
            $result = json_decode((string) ($task->result ?? ''), true);
            if (! is_array($result)) {
                continue;
            }
            $username = (string) ($result['username'] ?? '');
            $archiveId = (string) ($result['archive_id'] ?? '');
            if ($username === '' || preg_match('/^[a-f0-9]{32}$/', $archiveId) !== 1) {
                continue;
            }
            $key = $username . ':' . $archiveId;
            if (isset($seen[$key])) {
                continue;
            }
            $path = $home . '/backups/accounts/' . $username . '/' . $archiveId . '.tar.gz';
            if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'username'   => $username,
                'archive_id' => $archiveId,
                'file'       => $archiveId . '.tar.gz',
                'path'       => $path,
                'size'       => is_int($result['size_bytes'] ?? null) ? (int) $result['size_bytes'] : null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * One specific archive, but only when the agent reported it AND it is
     * really on disk in our own backup store.
     *
     * @return array{username:string,archive_id:string,file:string,path:string}|null
     */
    public static function archiveFor(string $username, string $archiveId): ?array
    {
        $username = Backup::tryUsername($username) ?? '';
        $archiveId = strtolower(trim($archiveId));
        if ($username === '' || preg_match('/^[a-f0-9]{32}$/', $archiveId) !== 1) {
            return null;
        }

        foreach (self::pushableArchives(50) as $archive) {
            if ($archive['username'] === $username && $archive['archive_id'] === $archiveId) {
                return $archive;
            }
        }

        return null;
    }

    /**
     * Copy the outcome of finished `backup.destination` push tasks back into
     * the ledger (and onto the destination row), so the WHM page is honest
     * without polling the agent.
     */
    public static function syncPushStatuses(int $scan = 200): int
    {
        $rows = BackupDestinationPush::query()
            ->where('status', 'queued')
            ->whereNotNull('task_id')
            ->orderByDesc('id')
            ->limit($scan)
            ->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        $ids = $rows->pluck('task_id')->filter(static fn ($id): bool => is_int($id) || is_numeric($id))
            ->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return 0;
        }

        $tasks = DB::table('tasks')
            ->where('server_id', Panel::serverId())
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $updated = 0;
        foreach ($rows as $row) {
            $task = $tasks->get((int) $row->task_id);
            if ($task === null) {
                continue;
            }
            $status = (string) $task->status;
            if (! in_array($status, ['success', 'failed', 'cancelled'], true)) {
                continue;
            }

            $result = json_decode((string) ($task->result ?? ''), true);
            $message = is_array($result) ? (string) ($result['error'] ?? ($result['message'] ?? '')) : '';
            if ($message === '') {
                $message = $status === 'success' ? 'pushed + sha256 verified' : (string) ($task->error ?? 'failed');
            }

            $row->update([
                'status'  => $status === 'success' ? 'done' : 'failed',
                'message' => substr($message, 0, 500),
            ]);

            $destination = $row->destination;
            if ($destination !== null) {
                $destination->update([
                    'last_push_at'      => now(),
                    'last_push_message' => substr($message, 0, 500),
                ]);
            }
            $updated++;
        }

        return $updated;
    }
}
