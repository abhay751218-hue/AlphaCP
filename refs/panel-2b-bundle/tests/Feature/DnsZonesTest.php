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

class DnsZonesTest extends TestCase
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

    public function test_root_can_list_account_zone(): void
    {
        $root = $this->userWithRole('root');
        [, $account] = $this->customerWithAccount();
        $this->asPanelUser($root)->get('/dns-zones')
            ->assertOk()
            ->assertSee('DNS Zone Manager')
            ->assertSee('shop.example.com')
            ->assertSee('custhost');

        $this->asPanelUser($root)->post('/dns-zones/' . $account->id . '/sync')
            ->assertRedirect(route('dns-zones.index'));
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'dns.zone')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_filter_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->customerWithAccount();
        $this->asPanelUser($root)->get('/dns-zones?q=' . urlencode('|/bin/sh'))
            ->assertOk()
            ->assertSee('Invalid domain')
            ->assertSee('No DNS zones match');
        $this->assertNull(DB::table('tasks')->where('type', 'dns.zone')->first());
    }

    public function test_path_escape_filter_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->customerWithAccount();
        $this->asPanelUser($root)->get('/dns-zones?q=' . urlencode('../etc'))
            ->assertOk()
            ->assertSee('Invalid domain');
        $this->assertNull(DB::table('tasks')->where('type', 'dns.zone')->first());
    }

    public function test_root_dashboard_has_zone_manager_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('DNS Zone Manager')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_zone_manager(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('DNS Zone Manager')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_zone_manager(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/dns-zones')->assertForbidden();
        $this->asPanelUser($mail)->get('/dns-zones')->assertForbidden();
    }

    public function test_root_can_add_dns_zone(): void
    {
        $root = $this->userWithRole('root');
        [, $account] = $this->customerWithAccount();
        $this->asPanelUser($root)->get('/dns-zones')
            ->assertOk()
            ->assertSee('Add DNS zone');

        $this->asPanelUser($root)->post('/dns-zones', [
            'account_id' => $account->id,
            'domain' => 'extra.example.com',
        ])->assertRedirect(route('dns-zones.index'));

        $this->assertSame('extra.example.com', $account->fresh()->domains()->where('domain', 'extra.example.com')->value('domain'));
        $add = DB::table('tasks')->where('account_id', $account->id)->where('type', 'domain.add')->first();
        $this->assertNotNull($add);
        $this->assertStringNotContainsString('|', (string) $add->payload);
        $zone = DB::table('tasks')->where('account_id', $account->id)->where('type', 'dns.zone')->first();
        $this->assertNotNull($zone);
    }

    public function test_pipe_add_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        [, $account] = $this->customerWithAccount();
        $this->asPanelUser($root)->post('/dns-zones', [
            'account_id' => $account->id,
            'domain' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->domains()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'domain.add')->first());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.zone')->first());
    }

    public function test_root_can_delete_extra_zone_not_main(): void
    {
        $root = $this->userWithRole('root');
        [, $account] = $this->customerWithAccount();
        $this->asPanelUser($root)->post('/dns-zones', [
            'account_id' => $account->id,
            'domain' => 'extra.example.com',
        ])->assertRedirect(route('dns-zones.index'));

        $this->asPanelUser($root)->delete('/dns-zones', [
            'account_id' => $account->id,
            'domain' => 'shop.example.com',
        ])->assertRedirect();
        $this->assertNull(DB::table('tasks')->where('type', 'domain.remove')->first());

        $this->asPanelUser($root)->delete('/dns-zones', [
            'account_id' => $account->id,
            'domain' => 'extra.example.com',
        ])->assertRedirect(route('dns-zones.index'));
        $remove = DB::table('tasks')->where('account_id', $account->id)->where('type', 'domain.remove')->first();
        $this->assertNotNull($remove);
        $this->assertStringNotContainsString('|', (string) $remove->payload);
    }
}
