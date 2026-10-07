<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
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

    public function test_root_can_queue_a_real_cpanel_import(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $this->asPanelUser($root)->get('/transfer-restore')
            ->assertOk()
            ->assertSee('Import an account archive')
            ->assertSee('/home/cpmove-alicehost.tar.gz');

        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => 'restore',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
        ])->assertRedirect(route('transfer-restore.index'));

        $row = TransferRestore::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('alicehost', $row->username);
        $this->assertSame('restore', $row->action);
        $task = DB::table('tasks')->where('type', 'backup.cpanel')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertIsArray($payload);
        $this->assertSame('/home/cpmove-alicehost.tar.gz', $payload['archive_path']);
        $this->assertSame('backup.cpanel', $payload['_confirm']);
        $this->assertSame('destructive', $task->safety);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_sha256_is_forwarded_and_must_be_64_hex(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $sha = str_repeat('a', 64);
        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => 'restore',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
            'sha256' => $sha,
        ])->assertRedirect();
        $payload = json_decode((string) DB::table('tasks')->where('type', 'backup.cpanel')->value('payload'), true);
        $this->assertSame($sha, $payload['sha256']);

        DB::table('tasks')->delete();
        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => 'restore',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
            'sha256' => 'not-a-hash',
        ])->assertSessionHasErrors('action');
        $this->assertSame(0, DB::table('tasks')->count());
    }

    public function test_mysql_restore_is_queued_after_the_home_import(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $sha = str_repeat('b', 64);

        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => 'restore',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
            'sha256' => $sha,
            'mysql' => '1',
            'mysql_only' => 'wp, shop',
        ])->assertRedirect(route('transfer-restore.index'));

        $row = TransferRestore::query()->first();
        $this->assertTrue($row->mysql);
        $this->assertSame('wp,shop', $row->mysql_only);
        $this->assertSame(['wp', 'shop'], $row->mysqlOnlyList());

        $this->assertSame(1, (int) DB::table('tasks')->where('type', 'backup.cpanel')->count());
        $task = DB::table('tasks')->where('type', 'db.restore')->first();
        $this->assertNotNull($task);
        $this->assertSame('destructive', $task->safety);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('/home/cpmove-alicehost.tar.gz', $payload['archive_path']);
        $this->assertSame($sha, $payload['sha256']);
        $this->assertSame('db.restore', $payload['_confirm']);
        $this->assertSame(['wp', 'shop'], $payload['only']);
    }

    public function test_mysql_restore_option_off_queues_only_the_home_import(): void
    {
        $root = $this->userWithRole('root');
        $this->account();

        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => 'restore',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
        ])->assertRedirect();

        $this->assertSame(0, (int) DB::table('tasks')->where('type', 'db.restore')->count());
        $this->assertFalse(TransferRestore::query()->first()->mysql);
    }

    public function test_mysql_only_list_is_validated(): void
    {
        $root = $this->userWithRole('root');
        $this->account();

        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => 'restore',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
            'mysql' => '1',
            'mysql_only' => '1bad;drop',
        ])->assertSessionHasErrors('mysql_only');

        $this->assertSame(0, DB::table('tasks')->count());
        $this->assertNull(TransferRestore::query()->first());

        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => 'restore',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
            'mysql' => '1',
            'mysql_only' => 'Wp, WP ,shop',
        ])->assertRedirect();
        $payload = json_decode((string) DB::table('tasks')->where('type', 'db.restore')->value('payload'), true);
        $this->assertSame(['wp', 'shop'], $payload['only'], 'duplicates collapse and names lowercase hote hain');
    }

    public function test_pipe_action_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'alicehost',
            'action' => '|/bin/sh',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
        ])->assertRedirect();
        $this->assertSame(0, TransferRestore::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.cpanel')->first());
    }

    public function test_path_escape_username_and_archive_are_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->account();
        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => '../etc',
            'action' => 'restore',
            'archive_path' => '/home/cpmove-alicehost.tar.gz',
        ])->assertRedirect();
        $this->assertSame(0, TransferRestore::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.cpanel')->first());

        foreach ([
            '/home/../etc/cpmove-alicehost.tar.gz',
            '/home/cpmove-alicehost.zip',
            'home/cpmove-alicehost.tar.gz',
            '/home/cpmove-alicehost.tar.gz|/bin/sh',
        ] as $bad) {
            $this->asPanelUser($root)->post('/transfer-restore', [
                'username' => 'alicehost',
                'action' => 'restore',
                'archive_path' => $bad,
            ])->assertSessionHasErrors('action');
        }
        $this->assertSame(0, DB::table('tasks')->count());
    }

    public function test_detected_archives_are_offered_with_their_size(): void
    {
        $root = $this->userWithRole('root');
        $tmp = sys_get_temp_dir() . '/acp-import-drop-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/incoming', 0755, true);
        $archive = $tmp . '/incoming/cpmove-alicehost.tar.gz';
        file_put_contents($archive, str_repeat('y', 2621440)); // 2.5 MB
        config(['acp.home' => $tmp]);

        try {
            $this->asPanelUser($root)->get('/transfer-restore')
                ->assertOk()
                ->assertSee('Detected archives')
                ->assertSee($archive)
                ->assertSee('2.5 MB');
        } finally {
            @unlink($archive);
            @rmdir($tmp . '/incoming');
            @rmdir($tmp);
        }
    }

    public function test_import_needs_an_existing_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-restore', [
            'username' => 'bobhost',
            'action' => 'restore',
            'archive_path' => '/home/cpmove-bobhost.tar.gz',
        ])->assertSessionHasErrors('username');
        $this->assertSame(0, DB::table('tasks')->count());
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
