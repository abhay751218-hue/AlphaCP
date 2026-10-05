<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\CronJob;

final class CronProvisioner
{
    public const FIELD = '/^[0-9*,\/-]{1,40}$/';

    public static function enqueue(Account $account): int
    {
        $jobs = $account->cronJobs()->where('enabled', true)->get()->map(static fn (CronJob $job): array => [
            'minute' => $job->minute,
            'hour' => $job->hour,
            'day' => $job->day,
            'month' => $job->month,
            'weekday' => $job->weekday,
            'command' => $job->command,
        ])->values()->all();

        return AccountProvisioner::enqueue($account, 'cron.set', [
            'username' => $account->username,
            'jobs' => $jobs,
        ]);
    }

    public static function limitReached(Account $account): bool
    {
        $max = (int) ($account->package?->MAXCRON ?? -1);
        if ($max < 0) {
            return false;
        }
        return $account->cronJobs()->count() >= $max;
    }

    public static function featureAllowed(Account $account): bool
    {
        $features = $account->package?->featureList?->features;
        if (! is_array($features)) {
            return true;
        }
        return ($features['cron'] ?? true) !== false;
    }
}
