<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BackupDestination;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\Panel;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * S10 remote backup destinations.
 *
 * The panel is only allowed to describe a destination — the upload itself and
 * every secret stay with the agent. These tests pin that contract: the task
 * payload must carry the action and the target, never a shell, never a path
 * outside our own backup store.
 */
class BackupDestinationsTest extends TestCase
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

    private function account(string $username = 'alicehost'): Account
    {
        $package = Package::query()->where('name', 'default')->firstOrFail();

        return Account::query()->create([
            'server_id'     => 1,
            'package_id'    => $package->id,
            'username'      => $username,
            'main_domain'   => $username . '.example.com',
            'contact_email' => $username . '@example.com',
            'home_path'     => '/home/' . $username,
            'php_version'   => '8.4',
            'quota_mb'      => 1024,
            'status'        => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function validDestination(): array
    {
        return [
            'name'             => 'offsite1',
            'host'             => 'backup.example.com',
            'port'             => 22,
            'username'         => 'backup',
            'path'             => '/srv/backups/alphacp',
            'auth'             => 'key',
            'host_fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'retention_days'   => 30,
        ];
    }

    public function test_root_can_open_the_destinations_page(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/backup-destinations')
            ->assertOk()
            ->assertSee('Remote destinations')
            ->assertSee('Save destination');
    }

    public function test_root_can_save_a_destination_and_queue_the_agent_task(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-destinations', $this->validDestination())
            ->assertRedirect(route('backup-destinations.index'));

        $row = BackupDestination::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('offsite1', $row->name);
        $this->assertSame('backup.example.com', $row->host);
        $this->assertSame('SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $row->host_fingerprint);

        $task = DB::table('tasks')->where('type', 'backup.destination')->first();
        $this->assertNotNull($task);
        $payload = (string) $task->payload;
        $this->assertStringContainsString('"action":"save"', $payload);
        $this->assertStringContainsString('/srv/backups/alphacp', $payload);
        $this->assertStringNotContainsString('|', $payload);
        $this->assertStringNotContainsString(';', $payload);
    }

    public function test_destination_without_host_key_pin_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-destinations', array_merge($this->validDestination(), [
            'host_fingerprint' => '',
        ]))->assertRedirect();

        $this->assertSame(0, BackupDestination::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.destination')->first());
    }

    public function test_path_escape_in_name_and_path_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        foreach ([['name' => '../../etc'], ['path' => '/srv/../../etc'], ['name' => 'bad name']] as $bad) {
            $this->asPanelUser($root)->post('/backup-destinations', array_merge($this->validDestination(), $bad))
                ->assertRedirect();
        }

        $this->assertSame(0, BackupDestination::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.destination')->first());
    }

    public function test_push_requires_an_archive_the_agent_really_made(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-destinations', $this->validDestination())->assertRedirect();

        // koi archive hai hi nahi -> koi push task nahi
        $this->asPanelUser($root)->post('/backup-destinations/push', [
            'name'    => 'offsite1',
            'archive' => 'alicehost:' . str_repeat('a', 32),
        ])->assertRedirect();

        $this->assertNull(DB::table('tasks')->where('type', 'backup.destination')
            ->where('payload', 'like', '%"action":"push"%')->first());
    }

    public function test_push_queues_a_real_archive_from_the_backup_store(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $this->asPanelUser($root)->post('/backup-destinations', $this->validDestination())->assertRedirect();

        $archiveId = str_repeat('b', 32);
        $file = rtrim((string) config('acp.home'), '/') . '/backups/accounts/alicehost/' . $archiveId . '.tar.gz';
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, 'archive-bytes');

        DB::table('tasks')->insert([
            'server_id'     => Panel::serverId(),
            'type'          => 'backup.archive',
            'safety'        => 'mutating',
            'payload'       => json_encode(['username' => 'alicehost', 'archive_id' => $archiveId]),
            'status'        => 'success',
            'result'        => json_encode([
                'archive_id' => $archiveId,
                'username'   => 'alicehost',
                'filename'   => $archiveId . '.tar.gz',
                'scope'      => 'home',
                'size_bytes' => 13,
                'sha256'     => str_repeat('c', 64),
            ]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->asPanelUser($root)->post('/backup-destinations/push', [
            'name'    => 'offsite1',
            'archive' => 'alicehost:' . $archiveId,
        ])->assertRedirect(route('backup-destinations.index'));

        $task = DB::table('tasks')->where('type', 'backup.destination')
            ->where('payload', 'like', '%"action":"push"%')->first();
        $this->assertNotNull($task);
        $payload = (string) $task->payload;
        $this->assertStringContainsString('/backups/accounts/alicehost/' . $archiveId . '.tar.gz', $payload);
        $this->assertSame(1, DB::table('backup_destination_pushes')->count());

        @unlink($file);
    }

    public function test_dry_run_push_command_reports_and_queues_nothing(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $this->asPanelUser($root)->post('/backup-destinations', $this->validDestination())->assertRedirect();

        $archiveId = str_repeat('d', 32);
        $file = rtrim((string) config('acp.home'), '/') . '/backups/accounts/alicehost/' . $archiveId . '.tar.gz';
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, 'archive-bytes');

        DB::table('tasks')->insert([
            'server_id'  => Panel::serverId(),
            'type'       => 'backup.archive',
            'safety'     => 'mutating',
            'payload'    => json_encode(['username' => 'alicehost', 'archive_id' => $archiveId]),
            'status'     => 'success',
            'result'     => json_encode([
                'archive_id' => $archiveId, 'username' => 'alicehost',
                'filename'   => $archiveId . '.tar.gz', 'scope' => 'home', 'size_bytes' => 13,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('alphacp:backup-destination-push', ['--dry-run' => true]);
        $output = Artisan::output();
        $this->assertStringContainsString('dry-run', $output);
        $this->assertSame(0, DB::table('backup_destination_pushes')->count());

        @unlink($file);
    }

    public function test_destroy_queues_remove_and_forgets_the_destination(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-destinations', $this->validDestination())->assertRedirect();
        $this->assertSame(1, BackupDestination::query()->count());

        $this->asPanelUser($root)->delete('/backup-destinations/offsite1')
            ->assertRedirect(route('backup-destinations.index'));
        $this->assertSame(0, BackupDestination::query()->count());

        $this->assertNotNull(DB::table('tasks')->where('type', 'backup.destination')
            ->where('payload', 'like', '%"action":"remove"%')->first());
    }

    public function test_customer_role_is_forbidden(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/backup-destinations')->assertForbidden();
    }
}
