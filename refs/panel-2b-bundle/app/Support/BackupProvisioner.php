<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\BackupJob;
use App\Models\BackupRestore;
use App\Models\BackupUserSelection;
use App\Models\BackupWizard;

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
}
