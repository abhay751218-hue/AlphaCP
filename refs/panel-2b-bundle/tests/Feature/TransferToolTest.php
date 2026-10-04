<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
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

    public function test_root_can_queue_a_real_transfer(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $this->asPanelUser($root)->get('/transfer-tool')
            ->assertOk()
            ->assertSee('Import a cPanel account from an archive')
            ->assertSee('/home/cpmove-alicehost.tar.gz');

        $this->asPanelUser($root)->post('/transfer-tool', [
            'username' => 'alicehost',
            'source' => 'source.example.com',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
        ])->assertRedirect(route('transfer-tool.index'));

        $row = TransferTool::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('alicehost', $row->username);
        $this->assertSame('source.example.com', $row->source);
        $task = DB::table('tasks')->where('type', 'backup.transfer')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertIsArray($payload);
        $this->assertSame('/home/cpmove-alicehost.tar.gz', $payload['archive_path']);
        $this->assertSame('backup.transfer', $payload['_confirm']);
        $this->assertSame('destructive', $task->safety);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_source_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $this->asPanelUser($root)->post('/transfer-tool', [
            'username' => 'alicehost',
            'source' => '|/bin/sh',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
        ])->assertRedirect();
        $this->assertSame(0, TransferTool::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.transfer')->first());
    }

    public function test_path_escape_source_and_archive_are_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $this->asPanelUser($root)->post('/transfer-tool', [
            'username' => 'alicehost',
            'source' => '../etc',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
        ])->assertRedirect();
        $this->assertSame(0, TransferTool::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.transfer')->first());

        $this->asPanelUser($root)->post('/transfer-tool', [
            'username' => 'alicehost',
            'source' => 'source.example.com',
            'archive_path' => '/home/../etc/passwd.tar.gz',
        ])->assertSessionHasErrors('source');
        $this->assertSame(0, DB::table('tasks')->count());
    }

    public function test_detected_archives_are_offered_on_the_page(): void
    {
        $root = $this->userWithRole('root');
        $tmp = sys_get_temp_dir() . '/acp-import-drop-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/incoming', 0755, true);
        $archive = $tmp . '/incoming/cpmove-alicehost.tar.gz';
        file_put_contents($archive, 'archive-bytes');
        config(['acp.home' => $tmp]);

        try {
            $this->asPanelUser($root)->get('/transfer-tool')
                ->assertOk()
                ->assertSee('cpanel-archives')
                ->assertSee($archive);
        } finally {
            @unlink($archive);
            @rmdir($tmp . '/incoming');
            @rmdir($tmp);
        }
    }

    public function test_transfer_needs_an_existing_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-tool', [
            'username' => 'bobhost',
            'source' => 'source.example.com',
            'archive_path' => '/home/cpmove-bobhost.tar.gz',
        ])->assertSessionHasErrors('username');
        $this->assertSame(0, DB::table('tasks')->count());
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
