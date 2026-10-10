<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\BackupConfig;
use App\Models\BackupUserSelection;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\BackupSchedule;
use App\Support\Panel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * S10 scheduled backups.
 *
 * Cron runs `artisan schedule:run` every minute; routes/console.php schedules
 * this command hourly. The command decides whether the configured window
 * (daily / weekly / monthly) is open — once per window, guarded by the marker
 * in App\Support\BackupSchedule and a cache lock.
 *
 * Which accounts: the WHM → Backup User Selection list when it has entries,
 * otherwise every active account. Accounts that already have a queued or
 * running `backup.archive` task are skipped, so a slow agent can never make
 * daily backups pile up. Retention (older archives pruned) is enforced by the
 * agent from the same WHM backup config on every new archive.
 *
 * The archives themselves are created by paneld (`backup.archive`) — the panel
 * never runs tar; this command only inserts task rows.
 */
class ScheduledBackupsCommand extends Command
{
    protected $signature = 'alphacp:scheduled-backups
                            {--force : run even when the schedule is disabled or the window was already used}
                            {--dry-run : show what would be queued, change nothing}';

    protected $description = 'Queue scheduled home backups (S10) from the WHM backup config';

    public function handle(): int
    {
        $now = Carbon::now();
        $config = BackupConfig::query()->orderByDesc('id')->first();
        $schedule = Backup::trySchedule((string) ($config?->schedule ?? '')) ?? 'disabled';
        $retention = Backup::tryRetention((string) ($config?->retention ?? '')) ?? 30;

        if ($schedule === 'disabled') {
            if (! $this->option('force')) {
                $this->info('Scheduled backups are disabled (WHM → Backup Config). Nothing queued.');

                return self::SUCCESS;
            }

            // --force on a disabled config: run one daily-shaped pass, no marker.
            $this->warn('Schedule is disabled — --force given, running one pass without touching the schedule marker.');
            $this->info('Retention stays ' . $retention . ' days (agent prunes expired archives on every new archive).');
            $this->info('Window: daily (forced)');
        } else {
            $this->info('Schedule: ' . $schedule . ' · window: ' . (string) BackupSchedule::windowKey($schedule, $now)
                . ' · retention: ' . $retention . ' days');
        }

        $busy = $this->busyAccountIds();
        $targets = $this->targets();

        if ($this->option('dry-run')) {
            $this->line('Dry run — no task queued, no marker written.');
            foreach ($targets as $account) {
                $this->line(sprintf(
                    '  %s  %s%s',
                    $account->username,
                    $account->main_domain ?? '-',
                    isset($busy[$account->id]) ? '  [skipped: backup already queued]' : '',
                ));
            }
            $this->line('Accounts considered: ' . $targets->count());

            return self::SUCCESS;
        }

        $lock = Cache::lock(BackupSchedule::LOCK, 3600);
        if (! $lock->get()) {
            $this->warn('Another scheduled-backup run is already in progress — nothing queued.');

            return self::FAILURE;
        }

        try {
            $window = $schedule === 'disabled' ? 'forced:' . $now->format('Y-m-d\TH:i') : (string) BackupSchedule::windowKey($schedule, $now);
            $forced = $schedule === 'disabled' || (bool) $this->option('force');

            if (! $forced) {
                if (! BackupSchedule::claim($window, $schedule, $now)) {
                    $this->info('Window ' . $window . ' is already used — no new archives this tick.');

                    return self::SUCCESS;
                }
            }

            $queued = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            foreach ($targets as $account) {
                if (isset($busy[$account->id])) {
                    $skipped++;
                    $this->line('  skip ' . $account->username . ' — a backup task is already queued/running');
                    continue;
                }

                try {
                    $archiveId = bin2hex(random_bytes(16));
                    $taskId = BackupProvisioner::enqueueArchive($account, $archiveId, 'scheduler');
                    $account->recordEvent('backup.archive.scheduled', $schedule . ':' . $archiveId, [
                        'task_id'  => $taskId,
                        'schedule' => $schedule,
                        'window'   => $window,
                    ]);
                    Audit::log('backup.schedule', 'info', 'account', $account->id, [
                        'task_id'    => $taskId,
                        'archive_id' => $archiveId,
                        'schedule'   => $schedule,
                        'window'     => $window,
                    ]);
                    $queued++;
                    $this->line('  queued ' . $account->username . ' → task #' . $taskId . ' (archive ' . $archiveId . ')');
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = $account->username . ': ' . $e->getMessage();
                    $this->error('  failed ' . $account->username . ': ' . $e->getMessage());
                }
            }

            if (! $forced) {
                BackupSchedule::finish($window, $queued, $skipped, $failed, $errors, $now);
            }

            $this->info(sprintf(
                '%s — queued %d, skipped %d, failed %d (window %s). Archives stay on the server until retention (%d days) prunes them.',
                $forced ? 'Forced scheduled-backup run' : 'Scheduled backups',
                $queued,
                $skipped,
                $failed,
                $window,
                $retention,
            ));

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * Active accounts; the WHM Backup User Selection list wins when it has rows.
     *
     * @return Collection<int, Account>
     */
    private function targets(): Collection
    {
        $selection = BackupUserSelection::query()->orderBy('id')->pluck('username')
            ->filter(static fn ($name): bool => is_string($name) && $name !== '')
            ->unique()->values()->all();

        $query = Account::query()->where('status', 'active')->orderBy('id');
        if ($selection !== []) {
            $query->whereIn('username', $selection);
        }

        return $query->get();
    }

    /** @return array<int, true> account ids that already have a pending archive task */
    private function busyAccountIds(): array
    {
        $ids = DB::table('tasks')
            ->where('server_id', Panel::serverId())
            ->where('type', 'backup.archive')
            ->whereIn('status', ['queued', 'running'])
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->all();

        $map = [];
        foreach ($ids as $id) {
            $map[(int) $id] = true;
        }

        return $map;
    }
}
