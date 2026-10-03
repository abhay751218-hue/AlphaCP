<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BackupUserSelection;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackupUserSelectionTest extends TestCase
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

    public function test_root_can_set_backup_user_selection(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/backup-user-selection')
            ->assertOk()
            ->assertSee('Backup user selection')
            ->assertSee('users.json');

        $this->asPanelUser($root)->post('/backup-user-selection', [
            'username' => 'alicehost',
        ])->assertRedirect(route('backup-user-selection.index'));

        $row = BackupUserSelection::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('alicehost', $row->username);
        $task = DB::table('tasks')->where('type', 'backup.users')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_username_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-user-selection', [
            'username' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, BackupUserSelection::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.users')->first());
    }

    public function test_path_escape_username_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/backup-user-selection', [
            'username' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, BackupUserSelection::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.users')->first());
    }

    public function test_root_dashboard_has_backup_user_selection_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Backup User Selection')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_backup_user_selection(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('File Restoration')
            ->assertDontSee('Backup User Selection')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_backup_user_selection(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/backup-user-selection')->assertForbidden();
        $this->asPanelUser($mail)->get('/backup-user-selection')->assertForbidden();
    }
}
