<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\BackupJob;
use App\Models\BackupRestore;
use App\Models\BackupUserSelection;
use App\Models\BackupWizard;
use App\Support\Files;

final class BackupProvisioner
{
    public static function enqueue(Account $account): int
    {
        $rows = $account->backupJobs()->orderBy('id')->get()->map(static fn (BackupJob $row): array => [
            'kind' => $row->kind,
            'path' => $row->path,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'backup.create', [
            'username' => $account->username,
            'jobs' => $rows,
        ]);
    }

    /** `$source` is 'panel' for a customer click, 'scheduler' for cron (WHM backup schedule). */
    public static function enqueueArchive(Account $account, string $archiveId, string $source = 'panel'): int
    {
        if (preg_match('/^[a-f0-9]{32}$/', $archiveId) !== 1) {
            throw new \InvalidArgumentException('Invalid backup archive id.');
        }

        return Paneld::enqueue('backup.archive', [
            'username' => $account->username,
            'archive_id' => $archiveId,
        ], $source, $account->id);
    }

    /** Destructive: restoring an archive replaces current files, so it carries `_confirm`. */
    public static function enqueueExtract(Account $account, string $archiveId, string $path = ''): int
    {
        if (preg_match('/^[a-f0-9]{32}$/', $archiveId) !== 1) {
            throw new \InvalidArgumentException('Invalid backup archive id.');
        }
        if ($path !== '' && Files::tryRel($path) === null) {
            throw new \InvalidArgumentException('Invalid restore path.');
        }

        $payload = [
            'username' => $account->username,
            'archive_id' => $archiveId,
            '_confirm' => 'backup.extract',
        ];
        if ($path !== '') {
            $payload['path'] = $path;
        }

        return Paneld::enqueue('backup.extract', $payload, 'panel', $account->id);
    }

    public static function limitReached(Account $account): bool
    {
        return $account->backupJobs()->count() >= Backup::MAX;
    }

    public static function enqueueWizard(Account $account): int
    {
        $row = BackupWizard::query()->where('account_id', $account->id)->first();
        $action = $row?->action ?? 'backup';
        $scope = $row?->scope ?? 'home';
        $action = Backup::tryAction((string) $action) ?? 'backup';
        $scope = Backup::tryScope((string) $scope) ?? 'home';

        return AccountProvisioner::enqueue($account, 'backup.wizard', [
            'username' => $account->username,
            'action' => $action,
            'scope' => $scope,
        ]);
    }

    public static function enqueueRestore(Account $account): int
    {
        $rows = $account->backupRestores()->orderBy('id')->get()->map(static fn (BackupRestore $row): array => [
            'path' => $row->path,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'backup.restore', [
            'username' => $account->username,
            'paths' => $rows,
        ]);
    }

    public static function restoreLimitReached(Account $account): bool
    {
        return $account->backupRestores()->count() >= Backup::MAX;
    }

    public static function enqueueConfig(string $schedule, int $retention): int
    {
        return Paneld::enqueue('backup.config', [
            'schedule' => $schedule,
            'retention' => $retention,
        ]);
    }

    public static function enqueueRestoration(string $mode, string $username): int
    {
        return Paneld::enqueue('backup.restoration', [
            'mode' => $mode,
            'username' => $username,
        ]);
    }

    public static function enqueueUsers(): int
    {
        $rows = BackupUserSelection::query()->orderBy('id')->get()->map(static fn (BackupUserSelection $row): array => [
            'username' => $row->username,
        ])->values()->all();

        return Paneld::enqueue('backup.users', [
            'users' => $rows,
        ]);
    }

    public static function userSelectionLimitReached(): bool
    {
        return BackupUserSelection::query()->count() >= Backup::MAX;
    }

    public static function enqueueFiledir(string $username, string $path): int
    {
        return Paneld::enqueue('backup.filedir', [
            'username' => $username,
            'path' => $path,
        ]);
    }

    public static function enqueueTransfer(string $username, string $source): int
    {
        return Paneld::enqueue('backup.transfer', [
            'username' => $username,
            'source' => $source,
        ]);
    }

    public static function enqueueCpanel(string $username, string $action): int
    {
        return Paneld::enqueue('backup.cpanel', [
            'username' => $username,
            'action' => $action,
        ]);
    }

    public static function enqueueReview(string $username, string $status): int
    {
        return Paneld::enqueue('backup.review', [
            'username' => $username,
            'status' => $status,
        ]);
    }
}
