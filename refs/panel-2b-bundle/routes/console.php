<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * S10 scheduled backups.
 *
 * Cron runs `php artisan schedule:run` every minute (installer writes
 * /etc/cron.d/alphacp-panel). This entry ticks hourly; the command itself
 * checks the WHM backup schedule window and the window marker, so a
 * daily/weekly/monthly run happens exactly once — and is caught up later the
 * same day when the server was down at the nominal time.
 */
Schedule::command('alphacp:scheduled-backups')
    ->hourly()
    ->withoutOverlapping(180)
    ->description('Queue scheduled home backups (S10)');

/*
 * S10 remote destinations. Runs right after the archive command: the hourly
 * tick first creates the archives, then this one uploads them to every enabled
 * destination (once per archive — the ledger makes it idempotent).
 */
Schedule::command('alphacp:backup-destination-push')
    ->hourlyAt(30)
    ->withoutOverlapping(180)
    ->description('Push home archives to remote backup destinations (S10)');
