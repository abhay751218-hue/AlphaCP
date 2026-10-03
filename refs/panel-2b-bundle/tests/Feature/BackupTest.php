<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BackupJob;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::query()->create([
            'username'      => 'u_' . $roleName,
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asPanelUser(User $user): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($user->fresh());
    }

    private function customerWithAccount(): array
    {
        $customer = $this->userWithRole('user');
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        $account = Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'owner_user_id' => $customer->id,
            'username' => 'custhost',
            'main_domain' => 'shop.example.com',
            'contact_email' => 'c@example.com',
            'home_path' => '/home/custhost',
            'php_version' => '8.4',
            'status' => 'active',
            'quota_mb' => 1024,
        ]);

        return [$customer, $account];
    }

    public function test_customer_can_queue_backup(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/backup')
            ->assertOk()
            ->assertSee('Backup')
            ->assertSee('jobs.json');

        $this->asPanelUser($customer)->post('/backup', [
            'kind' => 'home',
            'path' => 'public_html',
        ])->assertRedirect(route('backup.index'));

        $row = BackupJob::query()->where('account_id', $account->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('home', $row->kind);
        $this->assertSame('public_html', $row->path);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'backup.create')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('home', $payload['jobs'][0]['kind']);
        $this->assertSame('public_html', $payload['jobs'][0]['path']);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_customer_can_queue_a_real_home_archive(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/backup')
            ->assertOk()
            ->assertSee('Create home archive')
            ->assertSee('home files only');

        $this->asPanelUser($customer)->post('/backup/archive')->assertRedirect(route('backup.index'));
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'backup.archive')->first();
        $this->assertNotNull($task);
        $this->assertSame('queued', $task->status);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('custhost', $payload['username']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $payload['archive_id']);
    }

    public function test_customer_can_download_only_a_checksum_verified_own_archive(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $archiveId = str_repeat('c', 32);
        $archiveDir = rtrim((string) config('acp.home'), '/') . '/backups/accounts/custhost';
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0750, true);
        }
        $archivePath = $archiveDir . '/' . $archiveId . '.tar.gz';
        $content = "verified fake archive bytes\n";
        file_put_contents($archivePath, $content);
        $taskId = DB::table('tasks')->insertGetId([
            'server_id' => 1,
            'type' => 'backup.archive',
            'safety' => 'mutating',
            'payload' => json_encode(['username' => 'custhost', 'archive_id' => $archiveId]),
            'result' => json_encode([
                'archive_id' => $archiveId,
                'username' => 'custhost',
                'scope' => 'home',
                'filename' => $archiveId . '.tar.gz',
                'size_bytes' => strlen($content),
                'sha256' => hash('sha256', $content),
                'created_at' => now()->toISOString(),
                'status' => 'ready',
            ]),
            'status' => 'success',
            'account_id' => $account->id,
            'requested_src' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->asPanelUser($customer)->get(route('backup.archive-download', ['archiveId' => $archiveId]))
            ->assertOk()
            ->assertDownload('alphacp-custhost-home-' . $archiveId . '.tar.gz');

        file_put_contents($archivePath, 'tampered bytes');
        $this->asPanelUser($customer)->get(route('backup.archive-download', ['archiveId' => $archiveId]))
            ->assertNotFound();
        file_put_contents($archivePath, $content);
        DB::table('tasks')->where('id', $taskId)->update(['account_id' => $account->id + 100000]);
        $this->asPanelUser($customer)->get(route('backup.archive-download', ['archiveId' => $archiveId]))
            ->assertNotFound();
        @unlink($archivePath);
        @unlink($archiveDir . '/' . $archiveId . '.json');
    }

    /** Seed a successful backup.archive task result (the proof download/restore require). */
    private function seedArchiveTask(Account $account, string $archiveId, ?int $accountId = null): int
    {
        $content = "verified fake archive bytes\n";

        return DB::table('tasks')->insertGetId([
            'server_id' => 1,
            'type' => 'backup.archive',
            'safety' => 'mutating',
            'payload' => json_encode(['username' => $account->username, 'archive_id' => $archiveId]),
            'result' => json_encode([
                'archive_id' => $archiveId,
                'username' => $account->username,
                'scope' => 'home',
                'filename' => $archiveId . '.tar.gz',
                'size_bytes' => strlen($content),
                'sha256' => hash('sha256', $content),
                'created_at' => now()->toISOString(),
                'status' => 'ready',
            ]),
            'status' => 'success',
            'account_id' => $accountId ?? $account->id,
            'requested_src' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_customer_can_queue_a_real_home_restore(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $archiveId = str_repeat('d', 32);
        $this->seedArchiveTask($account, $archiveId);

        $this->asPanelUser($customer)->get('/backup')
            ->assertOk()
            ->assertSee('Restore home from this archive')
            ->assertSee('Restore history');

        $this->asPanelUser($customer)
            ->post('/backup/archive/' . $archiveId . '/restore', ['confirm_username' => 'custhost'])
            ->assertRedirect(route('backup.index'));

        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'backup.recover')->first();
        $this->assertNotNull($task);
        $this->assertSame('queued', $task->status);
        $this->assertSame('destructive', $task->safety);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('custhost', $payload['username']);
        $this->assertSame($archiveId, $payload['archive_id']);
        $this->assertSame('backup.recover', $payload['_confirm']);
        $this->assertStringNotContainsString('|', (string) $task->payload);

        $audit = DB::table('audit_logs')->where('action', 'backup.recover')->first();
        $this->assertNotNull($audit);
        $this->assertSame('critical', $audit->severity);
        $this->assertSame($account->id, (int) $audit->target_id);
        $meta = json_decode((string) $audit->meta, true);
        $this->assertSame($archiveId, $meta['archive_id']);

        $event = DB::table('account_events')->where('event', 'backup.recover.queued')->first();
        $this->assertNotNull($event);
    }

    public function test_restore_requires_the_typed_username(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $archiveId = str_repeat('e', 32);
        $this->seedArchiveTask($account, $archiveId);

        $this->asPanelUser($customer)
            ->post('/backup/archive/' . $archiveId . '/restore', ['confirm_username' => 'somebodyelse'])
            ->assertRedirect();
        $this->assertSame(0, DB::table('tasks')->where('type', 'backup.recover')->count());

        $this->asPanelUser($customer)
            ->post('/backup/archive/' . $archiveId . '/restore', [])
            ->assertSessionHasErrors('confirm_username');
        $this->assertSame(0, DB::table('tasks')->where('type', 'backup.recover')->count());
    }

    public function test_restore_needs_a_completed_own_archive(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $unknown = str_repeat('1', 32);
        $this->asPanelUser($customer)
            ->post('/backup/archive/' . $unknown . '/restore', ['confirm_username' => 'custhost'])
            ->assertRedirect();
        $this->assertSame(0, DB::table('tasks')->where('type', 'backup.recover')->count());

        // A completed archive that belongs to another account id must not be restorable.
        $foreign = str_repeat('2', 32);
        $this->seedArchiveTask($account, $foreign, $account->id + 100000);
        $this->asPanelUser($customer)
            ->post('/backup/archive/' . $foreign . '/restore', ['confirm_username' => 'custhost'])
            ->assertRedirect();
        $this->assertSame(0, DB::table('tasks')->where('type', 'backup.recover')->count());
    }

    public function test_restore_rejects_a_hostile_archive_id(): void
    {
        [$customer] = $this->customerWithAccount();
        // The route pins {archiveId} to ^[a-f0-9]{32}$, so a hostile id never reaches
        // the controller. Unknown POST URLs answer 405 in this panel because the
        // global fallback route is GET/HEAD-only; both statuses are fail-closed.
        foreach (['../etc', rawurlencode('../etc'), str_repeat('a', 31) . 'Z', 'a|b'] as $hostile) {
            $response = $this->asPanelUser($customer)
                ->post('/backup/archive/' . $hostile . '/restore', ['confirm_username' => 'custhost']);
            $this->assertContains($response->getStatusCode(), [404, 405], 'hostile id: ' . $hostile);
        }
        $this->assertSame(0, DB::table('tasks')->where('type', 'backup.recover')->count());
    }

    public function test_restore_is_blocked_for_suspended_accounts(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $archiveId = str_repeat('3', 32);
        $this->seedArchiveTask($account, $archiveId);
        $account->update(['status' => 'suspended']);

        $this->asPanelUser($customer)
            ->post('/backup/archive/' . $archiveId . '/restore', ['confirm_username' => 'custhost'])
            ->assertRedirect();
        $this->assertSame(0, DB::table('tasks')->where('type', 'backup.recover')->count());
    }

    public function test_pipe_kind_is_rejected(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/backup', [
            'kind' => '|/bin/sh',
            'path' => '',
        ])->assertRedirect();
        $this->assertSame(0, BackupJob::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.create')->first());
    }

    public function test_path_escape_is_rejected(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/backup', [
            'kind' => 'home',
            'path' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, BackupJob::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.create')->first());
    }

    public function test_customer_dashboard_has_backup_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Backup')
            ->assertDontSee('Create Account');
    }

    public function test_mail_role_cannot_open_backup(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/backup')->assertForbidden();
    }

    public function test_root_whm_hides_backup_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Backup<');
    }
}
