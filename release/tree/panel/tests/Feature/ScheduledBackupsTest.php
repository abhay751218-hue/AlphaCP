<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BackupConfig;
use App\Models\BackupUserSelection;
use App\Models\Package;
use App\Support\BackupSchedule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S10 scheduled backups (routes/console.php → alphacp:scheduled-backups).
 *
 * The cron tick itself is the installer's job; what is tested here is the
 * decision logic an operator depends on: the WHM schedule gate, the
 * once-per-window marker, the user-selection scope, and the no-pile-up rule.
 */
class ScheduledBackupsTest extends TestCase
{
    use RefreshDatabase;

    private string $marker = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // The window marker must never leak between tests (or onto the real
        // storage/app/private path while the suite runs).
        $this->marker = sys_get_temp_dir() . '/acp-schedule-' . bin2hex(random_bytes(6)) . '.json';
        config(['acp.backup_schedule.file' => $this->marker]);
    }

    protected function tearDown(): void
    {
        if ($this->marker !== '' && is_file($this->marker)) {
            @unlink($this->marker);
        }
        parent::tearDown();
    }

    private function package(): Package
    {
        return Package::query()->where('name', 'default')->firstOrFail();
    }

    private function account(string $username, string $status = 'active'): Account
    {
        return Account::query()->create([
            'server_id'     => 1,
            'package_id'    => $this->package()->id,
            'username'      => $username,
            'main_domain'   => $username . '.example.com',
            'contact_email' => $username . '@example.com',
            'home_path'     => '/home/' . $username,
            'php_version'   => '8.4',
            'quota_mb'      => 1024,
            'status'        => $status,
        ]);
    }

    private function config(string $schedule, int $retention = 14): void
    {
        BackupConfig::query()->create(['schedule' => $schedule, 'retention' => $retention]);
    }

    private function archiveCount(): int
    {
        return (int) DB::table('tasks')->where('type', 'backup.archive')->count();
    }

    /**
     * Run the scheduler command. Uses Artisan::call + output buffering rather
     * than $this->artisan(): the sandbox patch (`mockConsoleOutput = false`)
     * makes $this->artisan() return the exit code instead of a PendingCommand.
     *
     * @param  array<string, bool>  $options
     * @return array{0:int,1:string} exit code + output
     */
    private function runScheduler(array $options = []): array
    {
        $code = Artisan::call('alphacp:scheduled-backups', $options);

        return [$code, Artisan::output()];
    }

    /** @return array<string, mixed> */
    private function markerState(): array
    {
        return is_file($this->marker) ? (array) json_decode((string) file_get_contents($this->marker), true) : [];
    }

    public function test_schedule_is_registered_hourly_in_the_console_routes(): void
    {
        $events = app(Schedule::class)->events();
        $found = null;
        foreach ($events as $event) {
            if (str_contains((string) $event->command, 'alphacp:scheduled-backups')) {
                $found = $event;
                break;
            }
        }

        $this->assertNotNull($found, 'alphacp:scheduled-backups is not registered in routes/console.php');
        $this->assertSame('0 * * * *', $found->expression, 'scheduled backups should tick hourly');
        $this->assertTrue($found->withoutOverlapping, 'the scheduler tick must not overlap itself');
    }

    public function test_disabled_config_queues_nothing(): void
    {
        $this->account('dailyhost');
        $this->config('disabled');

        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('disabled', $out);

        $this->assertSame(0, $this->archiveCount());
        $this->assertSame([], $this->markerState());
    }

    public function test_daily_schedule_queues_one_archive_per_active_account_once_per_window(): void
    {
        $this->account('dailyhost');
        $this->account('secondhost');
        $this->account('frozenhost', 'suspended');
        $this->account('gonehost', 'terminated');
        $this->config('daily');

        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code, $out);

        $this->assertSame(2, $this->archiveCount(), 'only active accounts get a scheduled archive');

        $tasks = DB::table('tasks')->where('type', 'backup.archive')->get();
        foreach ($tasks as $task) {
            $this->assertSame('scheduler', $task->requested_src);
            $this->assertSame('queued', $task->status);
            $payload = (array) json_decode((string) $task->payload, true);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $payload['archive_id']);
            $this->assertSame(['username', 'archive_id'], array_keys($payload));
        }

        $state = $this->markerState();
        $this->assertSame('daily', $state['schedule']);
        $this->assertSame(now()->format('Y-m-d'), $state['window']);
        $this->assertSame(2, $state['queued']);

        // Same window again (next hourly tick) → nothing new.
        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('already used', $out);
        $this->assertSame(2, $this->archiveCount());

        // Next day → the window opens again (yesterday's tasks are long done).
        DB::table('tasks')->where('type', 'backup.archive')->update(['status' => 'success']);
        $this->travel(1)->day();
        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code, $out);
        $this->assertSame(4, $this->archiveCount());
        $this->travelBack();
    }

    public function test_weekly_and_monthly_windows_open_once_per_iso_week_and_month(): void
    {
        $this->account('weekhost');
        $this->config('weekly');

        // Wed 2026-10-07 → queue; same ISO week Thursday → nothing.
        $this->travelTo('2026-10-07 03:00:00');
        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code, $out);
        $this->assertSame(1, $this->archiveCount());
        $this->assertSame('2026-W41', $this->markerState()['window']);

        $this->travelTo('2026-10-08 03:00:00');
        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code, $out);
        $this->assertSame(1, $this->archiveCount());

        // Next ISO week → new window (previous week's task finished).
        DB::table('tasks')->where('type', 'backup.archive')->update(['status' => 'success']);
        $this->travelTo('2026-10-14 03:00:00');
        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code, $out);
        $this->assertSame(2, $this->archiveCount());

        // Monthly: same month twice → one run.
        DB::table('tasks')->where('type', 'backup.archive')->update(['status' => 'success']);
        BackupConfig::query()->orderByDesc('id')->firstOrFail()->update(['schedule' => 'monthly']);
        $this->travelTo('2026-11-02 03:00:00');
        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code, $out);
        $this->assertSame(3, $this->archiveCount());
        $this->assertSame('2026-11', $this->markerState()['window']);

        $this->travelTo('2026-11-20 03:00:00');
        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code, $out);
        $this->assertSame(3, $this->archiveCount());

        $this->travelBack();
    }

    public function test_backup_user_selection_scopes_the_scheduler(): void
    {
        $this->account('chosenhost');
        $this->account('otherhost');
        $this->config('daily');
        BackupUserSelection::query()->create(['username' => 'chosenhost']);

        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code, $out);

        $tasks = DB::table('tasks')->where('type', 'backup.archive')->get();
        $this->assertCount(1, $tasks);
        $payload = (array) json_decode((string) $tasks->first()->payload, true);
        $this->assertSame('chosenhost', $payload['username']);
    }

    public function test_account_with_a_pending_archive_task_is_skipped(): void
    {
        $busy = $this->account('busyhost');
        $this->account('freehost');
        $this->config('daily');

        DB::table('tasks')->insert([
            'server_id'    => 1,
            'account_id'   => $busy->id,
            'type'         => 'backup.archive',
            'safety'       => 'mutating',
            'payload'      => json_encode(['username' => 'busyhost', 'archive_id' => str_repeat('b', 32)]),
            'status'       => 'running',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        [$code, $out] = $this->runScheduler();
        $this->assertSame(0, $code, $out);

        $payloads = DB::table('tasks')->where('type', 'backup.archive')
            ->get()->map(static fn ($t): array => (array) json_decode((string) $t->payload, true))->all();
        $usernames = array_column($payloads, 'username');
        sort($usernames);
        $this->assertSame(['busyhost', 'freehost'], $usernames, 'the running task is not duplicated');

        $state = $this->markerState();
        $this->assertSame(1, $state['queued']);
        $this->assertSame(1, $state['skipped']);
    }

    public function test_dry_run_queues_nothing_and_writes_no_marker(): void
    {
        $this->account('dryhost');
        $this->config('daily');

        [$code, $out] = $this->runScheduler(['--dry-run' => true]);
        $this->assertSame(0, $code, $out);

        $this->assertSame(0, $this->archiveCount());
        $this->assertSame([], $this->markerState());
    }

    public function test_force_runs_even_when_disabled_and_does_not_claim_the_window(): void
    {
        $this->account('forcehost');
        $this->config('disabled');

        [$code, $out] = $this->runScheduler(['--force' => true]);
        $this->assertSame(0, $code, $out);

        $this->assertSame(1, $this->archiveCount());
        $this->assertSame([], $this->markerState(), 'a forced run must not consume the daily window');
    }

    public function test_support_class_window_keys_and_state(): void
    {
        $now = \Illuminate\Support\Carbon::parse('2026-10-04 09:30:00');

        $this->assertSame('2026-10-04', BackupSchedule::windowKey('daily', $now));
        $this->assertSame('2026-W40', BackupSchedule::windowKey('weekly', $now));
        $this->assertSame('2026-10', BackupSchedule::windowKey('monthly', $now));
        $this->assertNull(BackupSchedule::windowKey('disabled', $now));
        $this->assertNull(BackupSchedule::windowKey(null, $now));

        $state = BackupSchedule::state('daily', 14, $now);
        $this->assertSame('daily', $state['schedule']);
        $this->assertSame(14, $state['retention']);
        $this->assertSame('2026-10-04', $state['window']);
        $this->assertTrue($state['window_open']);
        $this->assertSame('2026-10-04 10:00 (hourly tick)', $state['next_due_at']);
        $this->assertSame($this->marker, $state['marker']);

        $this->assertTrue(BackupSchedule::claim('2026-10-04', 'daily', $now));
        $this->assertFalse(BackupSchedule::claim('2026-10-04', 'daily', $now), 'a claimed window stays claimed');
        $this->assertTrue(BackupSchedule::finish('2026-10-04', 3, 1, 0, [], $now));

        $after = BackupSchedule::state('daily', 14, $now);
        $this->assertFalse($after['window_open']);
        $this->assertNull($after['next_due_at']);
        $this->assertSame(3, $after['queued']);
        $this->assertSame(1, $after['skipped']);
        $this->assertSame(0, $after['failed']);
        $this->assertNotNull($after['last_run_at']);

        // `disabled` never opens a window, even with a stale marker.
        $this->assertFalse(BackupSchedule::state('disabled', 14, $now)['window_open']);
    }
}
