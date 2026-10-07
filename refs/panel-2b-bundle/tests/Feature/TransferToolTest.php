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
            ->assertSee('Import an account from an archive')
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

    public function test_remote_pull_probe_enqueues_a_host_key_check_only(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-tool/probe', [
            'host'        => 'old.example.com',
            'port'        => 22,
            'user'        => 'root',
            'remote_path' => '/home/cpmove-alicehost.tar.gz',
        ])->assertRedirect(route('transfer-tool.index'));

        $task = DB::table('tasks')->where('type', 'backup.pull')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['probe']);
        $this->assertSame('old.example.com', $payload['host']);
        $this->assertSame('/home/cpmove-alicehost.tar.gz', $payload['remote_path']);
        // probe downloads nothing and carries no secret
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('private_key', $payload);
        $this->assertSame('backup.pull', $payload['_confirm']);
        $this->assertSame('mutating', $task->safety);
    }

    public function test_remote_pull_requires_a_pinned_host_key(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/transfer-tool/pull', [
            'host'        => 'old.example.com',
            'user'        => 'root',
            'remote_path' => '/home/cpmove-alicehost.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
        ])->assertRedirect();
        $this->assertNull(DB::table('tasks')->where('type', 'backup.pull')->first());

        // with the fingerprint pinned it goes through
        $this->asPanelUser($root)->post('/transfer-tool/pull', [
            'host'             => 'old.example.com',
            'user'             => 'root',
            'remote_path'      => '/home/cpmove-alicehost.tar.gz',
            'auth'             => 'key',
            'private_key'      => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'host_fingerprint' => 'SHA256:8Ph7mQ0FakeFingerprintAAAAAAAAAAAAAAAAAAAAAAA',
        ])->assertRedirect(route('transfer-tool.index'));

        $task = DB::table('tasks')->where('type', 'backup.pull')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('probe', $payload);
        $this->assertSame('SHA256:8Ph7mQ0FakeFingerprintAAAAAAAAAAAAAAAAAAAAAAA', $payload['host_fingerprint']);
        $this->assertSame('key', $payload['auth']);
    }

    public function test_remote_pull_rejects_host_smuggling_and_bad_paths(): void
    {
        $root = $this->userWithRole('root');
        $bad = [
            ['host' => '-oProxyCommand=evil.example.com'],
            ['host' => 'old.example.com|/bin/sh'],
            ['host' => 'old example.com'],
            ['remote_path' => '/home/../etc/passwd.tar.gz'],
            ['remote_path' => 'relative.tar.gz'],
            ['dest_name' => 'evil.sh'],
            ['host_fingerprint' => 'not-a-fingerprint'],
        ];
        foreach ($bad as $override) {
            $this->asPanelUser($root)->post('/transfer-tool/pull', array_merge([
                'host'             => 'old.example.com',
                'user'             => 'root',
                'remote_path'      => '/home/cpmove-alicehost.tar.gz',
                'auth'             => 'key',
                'private_key'      => '-----BEGIN OPENSSH PRIVATE KEY-----',
                'host_fingerprint' => 'SHA256:8Ph7mQ0FakeFingerprintAAAAAAAAAAAAAAAAAAAAAAA',
            ], $override))->assertRedirect();
            $this->assertNull(
                DB::table('tasks')->where('type', 'backup.pull')->first(),
                'rejected: ' . json_encode($override)
            );
        }
    }

    public function test_remote_pull_password_needs_the_password_and_key_needs_the_key(): void
    {
        $root = $this->userWithRole('root');
        $base = [
            'host'             => 'old.example.com',
            'user'             => 'root',
            'remote_path'      => '/home/cpmove-alicehost.tar.gz',
            'host_fingerprint' => 'SHA256:8Ph7mQ0FakeFingerprintAAAAAAAAAAAAAAAAAAAAAAA',
        ];
        $this->asPanelUser($root)->post('/transfer-tool/pull', $base + ['auth' => 'password'])
            ->assertSessionHasErrors('password');
        $this->asPanelUser($root)->post('/transfer-tool/pull', $base + ['auth' => 'key'])
            ->assertSessionHasErrors('private_key');
        $this->assertNull(DB::table('tasks')->where('type', 'backup.pull')->first());
    }

    public function test_transfer_tool_page_offers_the_remote_pull_form(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/transfer-tool')
            ->assertOk()
            ->assertSee('Purane server se archive khinch lao')
            ->assertSee(route('transfer-tool.probe'), false)
            ->assertSee(route('transfer-tool.pull'), false)
            ->assertSee('Fingerprint lao');
    }
}
