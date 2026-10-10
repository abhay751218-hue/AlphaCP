<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RemoteMysqlTest extends TestCase
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

    public function test_customer_can_add_remote_host(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/remote-mysql')
            ->assertOk()
            ->assertSee('Remote MySQL')
            ->assertSee('remote.json');

        $this->asPanelUser($customer)->post('/remote-mysql', [
            'host' => '203.0.113.10',
        ])->assertRedirect(route('remote-mysql.index'));

        $row = $account->fresh()->remoteHosts()->first();
        $this->assertNotNull($row);
        $this->assertSame('203.0.113.10', $row->host);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'db.remote')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('203.0.113.10', $payload['hosts'][0]['host']);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_host_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/remote-mysql', [
            'host' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->remoteHosts()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'db.remote')->first());
    }

    public function test_path_escape_host_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/remote-mysql', [
            'host' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->remoteHosts()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'db.remote')->first());
    }

    public function test_customer_dashboard_has_remote_mysql_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Remote MySQL')
            ->assertDontSee('Create Account');
    }

    public function test_mail_role_cannot_open_remote_mysql(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/remote-mysql')->assertForbidden();
    }

    public function test_root_whm_hides_remote_mysql_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Remote MySQL<');
    }
}
