<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TransferTool;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransferToolTest extends TestCase
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

    public function test_root_can_set_transfer_tool(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/transfer-tool')
            ->assertOk()
            ->assertSee('Transfer tool')
            ->assertSee('transfer.json');

        $this->asPanelUser($root)->post('/transfer-tool', [
            'username' => 'alicehost',
            'source' => 'source.example.com',
        ])->assertRedirect(route('transfer-tool.index'));

        $row = TransferTool::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('alicehost', $row->username);
        $this->assertSame('source.example.com', $row->source);
        $task = DB::table('tasks')->where('type', 'backup.transfer')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_source_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-tool', [
            'username' => 'alicehost',
            'source' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, TransferTool::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.transfer')->first());
    }

    public function test_path_escape_source_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-tool', [
            'username' => 'alicehost',
            'source' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, TransferTool::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.transfer')->first());
    }

    public function test_root_dashboard_has_transfer_tool_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Transfer Tool')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_transfer_tool(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('File Restoration')
            ->assertDontSee('Transfer Tool')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_transfer_tool(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/transfer-tool')->assertForbidden();
        $this->asPanelUser($mail)->get('/transfer-tool')->assertForbidden();
    }
}
