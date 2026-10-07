<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BackupDestination;
use App\Models\BackupDestinationPush;
use App\Support\Audit;
use App\Support\BackupDestinations;
use App\Support\BackupProvisioner;
use Illuminate\Console\Command;

/**
 * S10 — push completed archives to the enabled remote destinations.
 *
 * Cron runs `artisan schedule:run` every minute; routes/console.php schedules
 * this command hourly, right after `alphacp:scheduled-backups` (which creates
 * the archives). It is idempotent by construction: the
 * `backup_destination_pushes` ledger has a unique (destination, archive) pair,
 * so an archive is uploaded to a destination exactly once — however often cron
 * ticks or an operator runs the command by hand.
 *
 * The upload itself is the agent's `backup.destination` task: pinned host key,
 * atomic `.part` upload and a remote sha256 check before the file becomes real.
 */
class BackupDestinationPushCommand extends Command
{
    protected $signature = 'alphacp:backup-destination-push
                            {--dry-run : show what would be pushed, change nothing}
                            {--limit=10 : how many uploads to queue in one run}';

    protected $description = 'Push completed home archives to remote backup destinations (S10)';

    public function handle(): int
    {
        $synced = BackupDestinations::syncPushStatuses();
        if ($synced > 0) {
            $this->line('  synced ' . $synced . ' finished push(es) back into the ledger');
        }

        $destinations = BackupDestination::query()->where('enabled', true)->orderBy('name')->get();
        if ($destinations->isEmpty()) {
            $this->info('No enabled backup destination — nothing to push (WHM → Backup Destinations).');

            return self::SUCCESS;
        }

        $archives = BackupDestinations::pushableArchives();
        if ($archives === []) {
            $this->info('No completed archive on this server yet — nothing to push.');

            return self::SUCCESS;
        }

        $limit = max(1, min(50, (int) $this->option('limit')));
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($destinations as $destination) {
            foreach ($archives as $archive) {
                if ($queued >= $limit) {
                    $this->line('  limit reached (' . $limit . ') — baaki archives agle tick me');
                    break 2;
                }

                $key = ['destination_id' => $destination->id, 'archive_id' => $archive['archive_id']];
                $existing = BackupDestinationPush::query()->where($key)->first();
                if ($existing !== null) {
                    $skipped++;
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line('  dry-run ' . $destination->name . ' ← ' . $archive['username'] . '/' . $archive['file']);
                    $queued++;
                    continue;
                }

                try {
                    $taskId = BackupProvisioner::enqueueDestination('push', [
                        'name'         => $destination->name,
                        'archive_path' => $archive['path'],
                    ]);
                    BackupDestinationPush::query()->create([
                        'destination_id' => $destination->id,
                        'username'       => $archive['username'],
                        'archive_id'     => $archive['archive_id'],
                        'file'           => $archive['file'],
                        'status'         => 'queued',
                        'task_id'        => $taskId,
                    ]);
                    Audit::log('backup.destination.push', 'info', 'server', null, [
                        'task_id'    => $taskId,
                        'name'       => $destination->name,
                        'archive'    => $archive['file'],
                        'username'   => $archive['username'],
                        'source'     => 'scheduler',
                    ]);
                    $queued++;
                    $this->line('  queued ' . $destination->name . ' ← ' . $archive['username'] . '/' . $archive['file'] . ' (task #' . $taskId . ')');
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error('  failed ' . $destination->name . ' ← ' . $archive['file'] . ': ' . $e->getMessage());
                }
            }
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run — nothing queued. Would push ' . $queued . ' archive(s).');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Backup destinations: queued %d, skipped %d (pehle ja chuke), failed %d.',
            $queued,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
