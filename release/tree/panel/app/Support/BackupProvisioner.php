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

    /**
     * Remote backup destination (S10) — save / test / push / browse / remove.
     *
     * NEVER pass a private key or password here from any stored value: the
     * agent owns those. The panel only ever sends the description (plus a key
     * or password the operator typed into the form right now).
     *
     * @param  array<string, mixed> $payload
     */
    public static function enqueueDestination(string $action, array $payload = []): int
    {
        if (! in_array($action, ['list', 'save', 'test', 'push', 'browse', 'remove'], true)) {
            throw new \InvalidArgumentException('Invalid backup destination action.');
        }

        return Paneld::enqueue('backup.destination', ['action' => $action] + $payload, 'panel');
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

    /**
     * WHM Transfer Tool: import a cPanel archive that already sits on this
     * server. Destructive (it replaces the account home) → `_confirm` is sent;
     * the agent engine keeps an `/home/.acp-prerestore-*` copy.
     */
    public static function enqueueTransfer(string $username, string $source, string $archivePath, string $sha256 = ''): int
    {
        $payload = [
            'username' => $username,
            'source' => $source,
            'archive_path' => $archivePath,
            '_confirm' => 'backup.transfer',
        ];
        if ($sha256 !== '') {
            $payload['sha256'] = $sha256;
        }

        return Paneld::enqueue('backup.transfer', $payload);
    }

    /** Import a cPanel archive into an existing account (WHM Transfer or Restore). */
    public static function enqueueCpanel(string $username, string $action, string $archivePath, string $sha256 = ''): int
    {
        $payload = [
            'username' => $username,
            'action' => $action,
            'archive_path' => $archivePath,
            '_confirm' => 'backup.cpanel',
        ];
        if ($sha256 !== '') {
            $payload['sha256'] = $sha256;
        }

        return Paneld::enqueue('backup.cpanel', $payload);
    }

    /**
     * S10 MySQL slice: restore the `mysql/*.sql` dumps of the SAME cPanel archive
     * into real MariaDB databases (`<account>_<suffix>`). Destructive → `_confirm`.
     */
    public static function enqueueMysqlRestore(string $username, string $action, string $archivePath, string $sha256 = '', array $only = []): int
    {
        $payload = [
            'username' => $username,
            'action' => $action,
            'archive_path' => $archivePath,
            '_confirm' => 'db.restore',
        ];
        if ($sha256 !== '') {
            $payload['sha256'] = $sha256;
        }
        if ($only !== []) {
            $payload['only'] = array_values($only);
        }

        return Paneld::enqueue('db.restore', $payload);
    }

    /**
     * S10 remote pull: ask the agent for the SSH host key fingerprint of the old
     * server WITHOUT downloading anything, so the operator can compare it
     * against something they trust before a single byte is transferred.
     *
     * @param array{host:string,port:int,user:string,remote_path:string} $spec
     */
    public static function enqueueRemoteProbe(array $spec): int
    {
        return Paneld::enqueue('backup.pull', [
            'host'          => $spec['host'],
            'port'          => $spec['port'],
            'user'          => $spec['user'],
            'remote_path'   => $spec['remote_path'],
            'probe'         => true,
            '_confirm'      => 'backup.pull',
        ]);
    }

    /**
     * S10 remote pull: fetch the archive over scp into the import drop dir.
     *
     * The host key MUST be pinned (the probe result) unless the operator
     * explicitly ticks `accept_host_key` — the agent refuses either way if the
     * fingerprint does not match what the server presents. A password is sent
     * only for this one task and the agent scrubs it from the stored payload.
     *
     * @param array{host:string,port:int,user:string,remote_path:string} $spec
     * @param array<string, mixed> $options
     */
    public static function enqueueRemotePull(array $spec, array $options = []): int
    {
        $payload = [
            'host'        => $spec['host'],
            'port'        => $spec['port'],
            'user'        => $spec['user'],
            'remote_path' => $spec['remote_path'],
            'auth'        => ($options['auth'] ?? 'key') === 'password' ? 'password' : 'key',
            '_confirm'    => 'backup.pull',
        ];
        $fingerprint = trim((string) ($options['host_fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            $payload['host_fingerprint'] = $fingerprint;
        } elseif (($options['accept_host_key'] ?? false) === true) {
            $payload['accept_host_key'] = true;
        }
        if (trim((string) ($options['private_key'] ?? '')) !== '') {
            $payload['private_key'] = (string) $options['private_key'];
        }
        if (trim((string) ($options['password'] ?? '')) !== '') {
            $payload['password'] = (string) $options['password'];
        }
        if (trim((string) ($options['dest_name'] ?? '')) !== '') {
            $payload['dest_name'] = (string) $options['dest_name'];
        }
        if (trim((string) ($options['sha256'] ?? '')) !== '') {
            $payload['sha256'] = (string) $options['sha256'];
        }
        $maxKbps = (int) ($options['max_kbps'] ?? 0);
        if ($maxKbps > 0) {
            $payload['max_kbps'] = min($maxKbps, 1000000);
        }
        if (($options['overwrite'] ?? false) === true) {
            $payload['overwrite'] = true;
        }

        return Paneld::enqueue('backup.pull', $payload);
    }

    public static function enqueueReview(string $username, string $status): int
    {
        return Paneld::enqueue('backup.review', [
            'username' => $username,
            'status' => $status,
        ]);
    }
}
