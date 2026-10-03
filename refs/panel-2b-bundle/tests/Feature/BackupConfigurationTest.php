<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BackupConfig;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackupConfigurationTest extends TestCase
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

    public function test_root_can_set_local_backup_configuration(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/backup-configuration')
            ->assertOk()
            ->assertSee('Backup configuration')
            ->assertSee('config.json');

        $this->asPanelUser($root)->post('/backup-configuration', [
            'enabled' => 'on',
            'schedule' => 'weekly',
            'retention_days' => '45',
            'destination' => 'local',
        ])->assertRedirect(route('backup-configuration.index'));

        $row = BackupConfig::query()->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->enabled);
        $this->assertSame('weekly', $row->schedule);
        $this->assertSame(45, $row->retention_days);
        $this->assertSame('local', $row->destination);
        $this->assertNull($row->remote_host);
        $task = DB::table('tasks')->where('type', 'backup.config')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_remote_configuration_requires_host_user_and_relative_path(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-configuration', [
            'enabled' => 'off',
            'schedule' => 'daily',
            'retention_days' => '30',
            'destination' => 'remote',
            'remote_host' => 'backup.example.com',
            'remote_user' => 'acpbackup',
            'remote_path' => 'backups/server1',
        ])->assertRedirect(route('backup-configuration.index'));

        $row = BackupConfig::query()->first();
        $this->assertNotNull($row);
        $this->assertFalse($row->enabled);
        $this->assertSame('remote', $row->destination);
        $this->assertSame('backup.example.com', $row->remote_host);
        $this->assertSame('acpbackup', $row->remote_user);
        $this->assertSame('backups/server1', $row->remote_path);
    }

    public function test_remote_host_pipe_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-configuration', [
            'schedule' => 'daily',
            'retention_days' => '30',
            'destination' => 'remote',
            'remote_host' => 'backup|sh',
            'remote_user' => 'acpbackup',
            'remote_path' => 'backups',
        ])->assertRedirect();
        $this->assertSame(0, BackupConfig::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.config')->first());
    }

    public function test_remote_path_escape_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-configuration', [
            'schedule' => 'daily',
            'retention_days' => '30',
            'destination' => 'remote',
            'remote_host' => 'backup.example.com',
            'remote_user' => 'acpbackup',
            'remote_path' => '/etc/passwd',
        ])->assertRedirect();
        $this->assertSame(0, BackupConfig::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.config')->first());
    }

    public function test_retention_bounds_are_enforced(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-configuration', [
            'schedule' => 'monthly',
            'retention_days' => '0',
            'destination' => 'local',
        ])->assertRedirect();
        $this->assertSame(0, BackupConfig::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.config')->first());
    }

    public function test_root_dashboard_has_backup_config(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Backup Config');
    }

    public function test_customer_dashboard_hides_backup_config(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Backup Config');
    }

    public function test_customer_and_mail_cannot_open_backup_configuration(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/backup-configuration')->assertForbidden();
        $this->asPanelUser($mail)->get('/backup-configuration')->assertForbidden();
    }
}
