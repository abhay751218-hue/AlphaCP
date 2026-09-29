<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Support\License\LicenseClient;
use Illuminate\Support\Facades\DB;

/**
 * Panel side of account provisioning: license gates, task enqueue, status sync.
 * The web process never touches Linux users — paneld does.
 */
final class AccountProvisioner
{
    public static function liveCount(): int
    {
        return Account::query()->whereNotIn('status', ['terminated'])->count();
    }

    /** @return array{ok:bool,reason:?string,status:array<string,mixed>} */
    public static function licenseGate(LicenseClient $client): array
    {
        $status = $client->ensureTrial();
        $state = (string) ($status['state'] ?? '');
        if (in_array($state, ['locked', 'invalid', 'uninitialized'], true)) {
            return ['ok' => false, 'reason' => 'license', 'status' => $status];
        }
        $max = $status['max_accounts'] ?? null;
        if (is_int($max) && $max >= 0 && self::liveCount() >= $max) {
            return ['ok' => false, 'reason' => 'cap', 'status' => $status];
        }
        return ['ok' => true, 'reason' => null, 'status' => $status];
    }

    /** @param array<string, mixed> $payload */
    public static function enqueue(Account $account, string $type, array $payload): int
    {
        $id = Paneld::enqueue($type, $payload, 'panel', $account->id);
        self::waitIfConfigured($id);
        self::refresh($account);

        return $id;
    }

    public static function refresh(Account $account): void
    {
        $task = DB::table('tasks')
            ->where('account_id', $account->id)
            ->orderByDesc('id')
            ->first();
        if ($task === null) {
            return;
        }

        $type = (string) $task->type;
        $status = (string) $task->status;
        $error = is_string($task->error ?? null) ? (string) $task->error : null;

        if ($status === 'failed') {
            $meta = $account->meta ?? [];
            $meta['last_error'] = $error;
            $account->forceFill(['meta' => $meta])->save();
            return;
        }
        if ($status !== 'success') {
            return;
        }

        match ($type) {
            'account.create' => $account->forceFill([
                'status' => 'active',
                'setup_completed_at' => now(),
                'meta' => array_merge($account->meta ?? [], ['last_error' => null]),
            ])->save(),
            'account.suspend' => $account->forceFill([
                'status' => 'suspended',
                'suspended_at' => $account->suspended_at ?? now(),
            ])->save(),
            'account.unsuspend' => $account->forceFill([
                'status' => 'active',
                'suspend_reason' => null,
                'suspended_at' => null,
            ])->save(),
            'account.terminate' => self::markTerminated($account),
            default => null,
        };
    }

    public static function markTerminated(Account $account): void
    {
        if ($account->isTerminated() && $account->trashed()) {
            return;
        }
        $stamp = $account->id;
        $account->forceFill([
            'status' => 'terminated',
            'terminated_at' => now(),
            'username' => $account->username . '.deleted.' . $stamp,
            'main_domain' => $account->main_domain . '.deleted.' . $stamp,
        ])->save();
        $account->delete();
    }

    private static function waitIfConfigured(int $taskId): void
    {
        $wait = app()->environment('testing') ? 0 : (int) config('acp.provision_wait', 25);
        if ($wait <= 0) {
            return;
        }
        $deadline = microtime(true) + $wait;
        while (microtime(true) < $deadline) {
            $task = DB::table('tasks')->where('id', $taskId)->first();
            if ($task && in_array($task->status, ['success', 'failed', 'cancelled'], true)) {
                return;
            }
            usleep(250_000);
        }
    }
}
