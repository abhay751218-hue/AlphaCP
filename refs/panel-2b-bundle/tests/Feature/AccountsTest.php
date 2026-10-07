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

class AccountsTest extends TestCase
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

    public function test_root_can_open_accounts_pages(): void
    {
        $root = $this->userWithRole('root');

        $this->asPanelUser($root)->get('/accounts')->assertOk()->assertSee('Hosting accounts');
        $this->asPanelUser($root)->get('/accounts/create')->assertOk()->assertSee('Create a New Account');
    }

    public function test_customer_cannot_list_or_create_accounts(): void
    {
        $customer = $this->userWithRole('user');

        $this->asPanelUser($customer)->get('/accounts')->assertForbidden();
        $this->asPanelUser($customer)->get('/accounts/create')->assertForbidden();
        $this->asPanelUser($customer)->post('/accounts', [])->assertForbidden();
    }

    public function test_create_rejects_reserved_and_invalid_usernames(): void
    {
        $root = $this->userWithRole('root');
        $package = Package::query()->where('name', 'default')->firstOrFail();

        $this->asPanelUser($root)->post('/accounts', [
            'username' => 'root',
            'main_domain' => 'shop.example.com',
            'contact_email' => 'a@example.com',
            'package_id' => $package->id,
            'php_version' => '8.4',
            'password' => 'CorrectHorse1',
        ])->assertSessionHasErrors('username');

        $this->asPanelUser($root)->post('/accounts', [
            'username' => 'Bad_User',
            'main_domain' => 'shop.example.com',
            'contact_email' => 'a@example.com',
            'package_id' => $package->id,
            'php_version' => '8.4',
            'password' => 'CorrectHorse1',
        ])->assertSessionHasErrors('username');
    }

    public function test_create_enqueues_account_create_and_makes_panel_user(): void
    {
        $root = $this->userWithRole('root');
        $package = Package::query()->where('name', 'default')->firstOrFail();

        $this->asPanelUser($root)->post('/accounts', [
            'username' => 'alicehost',
            'main_domain' => 'shop.example.com',
            'contact_email' => 'alice@example.com',
            'package_id' => $package->id,
            'php_version' => '8.4',
            'password' => 'CorrectHorse1',
        ])->assertRedirect();

        $account = Account::query()->where('username', 'alicehost')->first();
        $this->assertNotNull($account);
        $this->assertSame('pending', $account->status);
        $this->assertSame('shop.example.com', $account->main_domain);
        $this->assertSame($package->id, $account->package_id);

        $owner = User::query()->where('username', 'alicehost')->first();
        $this->assertNotNull($owner);
        $this->assertTrue($owner->force_password_change);

        $task = DB::table('tasks')->where('account_id', $account->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('account.create', $task->type);
        $this->assertSame('queued', $task->status);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('alicehost', $payload['username']);
        $this->assertSame('shop.example.com', $payload['domain']);
        $this->assertStringStartsWith('$6$', (string) $payload['shadow_hash']);
        $this->assertArrayNotHasKey('password', $payload);

        $this->assertDatabaseHas('audit_logs', ['action' => 'account.create', 'target_id' => $account->id]);
        $this->assertDatabaseHas('domains', [
            'account_id' => $account->id,
            'domain' => 'shop.example.com',
            'type' => 'main',
        ]);
    }

    public function test_duplicate_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $package = Package::query()->where('name', 'default')->firstOrFail();
        Account::query()->create([
            'server_id' => 1,
            'package_id' => $package->id,
            'username' => 'firstacct',
            'main_domain' => 'shop.example.com',
            'contact_email' => 'a@example.com',
            'home_path' => '/home/firstacct',
            'status' => 'active',
            'quota_mb' => 1024,
        ]);

        $this->asPanelUser($root)->post('/accounts', [
            'username' => 'secondacct',
            'main_domain' => 'shop.example.com',
            'contact_email' => 'b@example.com',
            'package_id' => $package->id,
            'php_version' => '8.4',
            'password' => 'CorrectHorse1',
        ])->assertSessionHasErrors('main_domain');
    }

    public function test_suspend_and_terminate_enqueue_agent_tasks(): void
    {
        $root = $this->userWithRole('root');
        $package = Package::query()->where('name', 'default')->firstOrFail();
        $account = Account::query()->create([
            'server_id' => 1,
            'package_id' => $package->id,
            'username' => 'bobhost',
            'main_domain' => 'bob.example.com',
            'contact_email' => 'bob@example.com',
            'home_path' => '/home/bobhost',
            'status' => 'active',
            'quota_mb' => 512,
        ]);

        $this->asPanelUser($root)->post("/accounts/{$account->id}/suspend", [
            'reason' => 'abuse',
        ])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['account_id' => $account->id, 'type' => 'account.suspend']);

        $this->asPanelUser($root)->post("/accounts/{$account->id}/terminate", [
            'confirm_username' => 'nope',
        ])->assertSessionHasErrors('confirm_username');

        $this->asPanelUser($root)->post("/accounts/{$account->id}/terminate", [
            'confirm_username' => 'bobhost',
        ])->assertRedirect(route('accounts.index'));
        $this->assertDatabaseHas('tasks', ['account_id' => $account->id, 'type' => 'account.terminate']);
        $term = DB::table('tasks')->where('type', 'account.terminate')->first();
        $payload = json_decode((string) $term->payload, true);
        $this->assertSame('account.terminate', $payload['_confirm']);
    }

    public function test_reseller_account_creation_records_reseller_ownership(): void
    {
        $reseller = $this->userWithRole('reseller');
        $package = Package::query()->where('name', 'default')->firstOrFail();

        $this->asPanelUser($reseller)->post('/accounts', [
            'username' => 'clienthost',
            'main_domain' => 'client.example.com',
            'contact_email' => 'client@example.com',
            'package_id' => $package->id,
            'php_version' => '8.4',
            'password' => 'CorrectHorse1',
        ])->assertRedirect();

        $account = Account::query()->where('username', 'clienthost')->firstOrFail();
        $this->assertSame($reseller->id, (int) $account->reseller_id);
        $this->assertDatabaseHas('users', [
            'username' => 'clienthost',
            'created_by' => $reseller->id,
        ]);
        $this->assertDatabaseHas('tasks', [
            'account_id' => $account->id,
            'type' => 'account.create',
        ]);
    }

    public function test_reseller_account_list_and_detail_are_scoped_to_the_reseller(): void
    {
        $reseller = $this->userWithRole('reseller');
        $role = Role::query()->where('name', 'reseller')->firstOrFail();
        $other = User::query()->create([
            'username' => 'other_reseller',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $package = Package::query()->where('name', 'default')->firstOrFail();
        $own = Account::query()->create([
            'server_id' => 1, 'package_id' => $package->id, 'reseller_id' => $reseller->id,
            'username' => 'ownhost', 'main_domain' => 'own.example.com',
            'contact_email' => 'own@example.com', 'home_path' => '/home/ownhost',
            'status' => 'active', 'quota_mb' => 1024,
        ]);
        $foreign = Account::query()->create([
            'server_id' => 1, 'package_id' => $package->id, 'reseller_id' => $other->id,
            'username' => 'foreignhost', 'main_domain' => 'foreign.example.com',
            'contact_email' => 'foreign@example.com', 'home_path' => '/home/foreignhost',
            'status' => 'active', 'quota_mb' => 1024,
        ]);

        $this->asPanelUser($reseller)->get('/accounts')
            ->assertOk()
            ->assertSee('own.example.com')
            ->assertDontSee('foreign.example.com');
        $this->asPanelUser($reseller)->get("/accounts/{$own->id}")->assertOk();
        $this->asPanelUser($reseller)->get("/accounts/{$foreign->id}")->assertNotFound();
        $this->asPanelUser($reseller)->post("/accounts/{$foreign->id}/suspend", ['reason' => 'test'])
            ->assertNotFound();
        $this->assertDatabaseMissing('tasks', ['account_id' => $foreign->id, 'type' => 'account.suspend']);
    }

    public function test_reseller_cannot_provision_with_another_resellers_package(): void
    {
        $reseller = $this->userWithRole('reseller');
        $role = Role::query()->where('name', 'reseller')->firstOrFail();
        $other = User::query()->create([
            'username' => 'other_reseller',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $foreignPackage = Package::query()->create([
            'owner_id' => $other->id,
            'name' => 'private-plan',
            'QUOTA' => 2048,
            'status' => 'active',
        ]);
        $defaultPackage = Package::query()->where('name', 'default')->firstOrFail();
        $ownAccount = Account::query()->create([
            'server_id' => 1,
            'package_id' => $defaultPackage->id,
            'reseller_id' => $reseller->id,
            'username' => 'ownclient',
            'main_domain' => 'ownclient.example.com',
            'contact_email' => 'owner@example.com',
            'home_path' => '/home/ownclient',
            'status' => 'active',
            'quota_mb' => 1024,
        ]);

        $this->asPanelUser($reseller)->get('/accounts/create')
            ->assertOk()
            ->assertDontSee('private-plan');
        $this->asPanelUser($reseller)->get('/accounts/' . $ownAccount->id)
            ->assertOk()
            ->assertDontSee('private-plan');
        $this->asPanelUser($reseller)->post('/accounts/' . $ownAccount->id . '/upgrade', [
            'package_id' => $foreignPackage->id,
        ])->assertNotFound();
        $this->assertSame($defaultPackage->id, $ownAccount->fresh()->package_id);
        $this->assertDatabaseMissing('tasks', [
            'account_id' => $ownAccount->id,
            'type' => 'account.setQuota',
        ]);

        $this->asPanelUser($reseller)->post('/accounts', [
            'username' => 'clienthost',
            'main_domain' => 'client.example.com',
            'contact_email' => 'client@example.com',
            'package_id' => $foreignPackage->id,
            'php_version' => '8.4',
            'password' => 'CorrectHorse1',
        ])->assertNotFound();
        $this->assertDatabaseMissing('accounts', ['username' => 'clienthost']);
    }

    public function test_customer_cannot_terminate(): void
    {
        $customer = $this->userWithRole('user');
        $package = Package::query()->where('name', 'default')->firstOrFail();
        $account = Account::query()->create([
            'server_id' => 1,
            'package_id' => $package->id,
            'username' => 'custhost',
            'main_domain' => 'cust.example.com',
            'contact_email' => 'c@example.com',
            'home_path' => '/home/custhost',
            'status' => 'active',
            'quota_mb' => 100,
        ]);

        $this->asPanelUser($customer)->post("/accounts/{$account->id}/terminate", [
            'confirm_username' => 'custhost',
        ])->assertForbidden();
    }
}
