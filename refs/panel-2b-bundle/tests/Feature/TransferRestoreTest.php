<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TransferRestore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransferRestoreTest extends TestCase
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

    public function test_root_can_set_transfer_restore(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/transfer-restore')
            ->assertOk()
            ->assertSee('Transfer or restore a cPanel account')
            ->assertSee('cpanel-account.json');

        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => 'restore',
        ])->assertRedirect(route('transfer-restore.index'));

        $row = TransferRestore::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('alicehost', $row->username);
        $this->assertSame('restore', $row->action);
        $task = DB::table('tasks')->where('type', 'backup.cpanel')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_action_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, TransferRestore::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.cpanel')->first());
    }

    public function test_path_escape_username_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => '../etc',
            'action' => 'restore',
        ])->assertRedirect();
        $this->assertSame(0, TransferRestore::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.cpanel')->first());
    }

    public function test_root_dashboard_has_transfer_restore_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Transfer or Restore a cPanel Account')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_transfer_restore(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('File Restoration')
            ->assertDontSee('Transfer or Restore a cPanel Account')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_transfer_restore(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/transfer-restore')->assertForbidden();
        $this->asPanelUser($mail)->get('/transfer-restore')->assertForbidden();
    }
}
