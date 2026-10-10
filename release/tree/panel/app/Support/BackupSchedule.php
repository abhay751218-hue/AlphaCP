<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * S10 scheduled backups — the window and the window marker.
 *
 * Cron calls `php artisan schedule:run` every minute (see installer: the
 * `/etc/cron.d/alphacp-panel` file) and Laravel then runs
 * `alphacp:scheduled-backups` once an hour. This class is what makes that
 * idempotent and honest:
 *
 *  - the WHM Backup Config schedule (daily / weekly / monthly / disabled)
 *    decides when a real backup window is open;
 *  - a marker file records the window that was already claimed, so a day,
 *    ISO week or month only ever produces ONE run of archives — no matter how
 *    often cron fires, how long the server was down, or whether an operator
 *    also runs the command by hand;
 *  - the WHM Backup Config page shows the marker back to the admin
 *    (last run, counts, when the next window opens).
 *
 * No tar, no shell, no pipe — the actual archive is the agent's
 * `backup.archive` task; this class only ever writes a small JSON marker.
 */
final class BackupSchedule
{
    /** Cache lock name: one scheduler run at a time. */
    public const LOCK = 'alphacp:backup-scheduler';

    /** @var list<string> */
    public const SCHEDULES = ['daily', 'weekly', 'monthly', 'disabled'];

    /** @var list<string> */
    private const MAX_ERRORS = 5;

    /** Marker file for the claimed window (overridable for tests). */
    public static function markerPath(): string
    {
        $configured = (string) config('acp.backup_schedule.file', '');

        return $configured !== '' ? $configured : storage_path('app/private/backup-schedule.json');
    }

    /**
     * The window key for a schedule at a given moment, or null when the
     * schedule is disabled/unknown (i.e. nothing may run).
     */
    public static function windowKey(?string $schedule, DateTimeInterface $now): ?string
    {
        return match ($schedule) {
            'daily'   => $now->format('Y-m-d'),
            // ISO year + ISO week, so a run late on Sunday still lands in the
            // week the admin expects.
            'weekly'  => $now->format('o-\WW'),
            'monthly' => $now->format('Y-m'),
            default   => null,
        };
    }

    /** True when the schedule is not disabled and the current window is still unclaimed. */
    public static function windowOpen(?string $schedule, DateTimeInterface $now): bool
    {
        $window = self::windowKey($schedule, $now);
        if ($window === null) {
            return false;
        }

        return (self::read()['window'] ?? null) !== $window;
    }

    /**
     * Claim the window. Returns false when this window was already claimed
     * (another run got here first, or the scheduler already ran today).
     */
    public static function claim(string $window, string $schedule, DateTimeInterface $now): bool
    {
        $state = self::read();
        if (($state['window'] ?? null) === $window) {
            return false;
        }

        self::write([
            'version'    => 1,
            'window'     => $window,
            'schedule'   => $schedule,
            'claimed_at' => $now->format('c'),
            'ran_at'     => null,
            'queued'     => null,
            'skipped'    => null,
            'failed'     => null,
            'errors'     => [],
        ]);

        return true;
    }

    /**
     * Record the outcome of the run that claimed $window.
     *
     * @param  list<string>  $errors
     */
    public static function finish(string $window, int $queued, int $skipped, int $failed, array $errors, DateTimeInterface $now): bool
    {
        $state = self::read();
        if (($state['window'] ?? null) !== $window) {
            return false; // someone else claimed a newer window meanwhile
        }

        $state['ran_at'] = $now->format('c');
        $state['queued'] = $queued;
        $state['skipped'] = $skipped;
        $state['failed'] = $failed;
        $state['errors'] = array_slice(array_values($errors), 0, self::MAX_ERRORS);

        self::write($state);

        return true;
    }

    /**
     * What the WHM Backup Config page shows.
     *
     * @return array<string, mixed>
     */
    public static function state(?string $schedule, ?int $retention, DateTimeInterface $now): array
    {
        $schedule = self::normalize($schedule);
        $state = self::read();
        $window = self::windowKey($schedule, $now);
        $claimed = $state['window'] ?? null;
        $open = $schedule !== 'disabled' && $window !== null && $claimed !== $window;

        // The command is scheduled hourly, so an open window is picked up on
        // the next tick — that is what "next run" means here.
        $nextDue = $open
            ? Carbon::instance($now)->addHour()->startOfHour()->format('Y-m-d H:i') . ' (hourly tick)'
            : null;

        return [
            'schedule'      => $schedule,
            'retention'     => $retention,
            'window'        => $window,
            'last_window'   => is_string($claimed) ? $claimed : null,
            'last_run_at'   => is_string($state['ran_at'] ?? null) ? $state['ran_at'] : (is_string($state['claimed_at'] ?? null) ? $state['claimed_at'] : null),
            'last_schedule' => is_string($state['schedule'] ?? null) ? $state['schedule'] : null,
            'queued'        => is_int($state['queued'] ?? null) ? $state['queued'] : null,
            'skipped'       => is_int($state['skipped'] ?? null) ? $state['skipped'] : null,
            'failed'        => is_int($state['failed'] ?? null) ? $state['failed'] : null,
            'errors'        => is_array($state['errors'] ?? null) ? array_values(array_filter($state['errors'], 'is_string')) : [],
            'window_open'   => $open,
            'next_due_at'   => $nextDue,
            'marker'        => self::markerPath(),
        ];
    }

    /** @return array<string, mixed> */
    public static function read(): array
    {
        $path = self::markerPath();
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write the marker. Throws when it cannot be written: without the marker
     * the daily/weekly/monthly gate is broken, and silently running a second
     * full backup of every account would be worse than a failed cron run.
     *
     * @param  array<string, mixed>  $state
     */
    private static function write(array $state): void
    {
        $path = self::markerPath();
        if (is_link($path)) {
            throw new \RuntimeException("backup schedule marker is a symlink: {$path}");
        }

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new \RuntimeException("backup schedule directory is not writable: {$dir}");
        }

        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($json)) {
            throw new \RuntimeException('backup schedule marker encoding failed');
        }

        if (@file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("backup schedule marker is not writable: {$path}");
        }

        @chmod($path, 0640);
    }

    private static function normalize(?string $schedule): string
    {
        $schedule = strtolower(trim((string) $schedule));

        return in_array($schedule, self::SCHEDULES, true) ? $schedule : 'disabled';
    }
}
