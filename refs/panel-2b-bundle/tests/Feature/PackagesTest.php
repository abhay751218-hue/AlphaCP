<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FeatureList;
use App\Models\Package;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\PackageLimits;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PackagesTest extends TestCase
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

    public function test_root_can_open_packages_page(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/packages')->assertOk()->assertSee('Hosting plans');
        $this->asPanelUser($root)->get('/packages/create')->assertOk()->assertSee('QUOTA');
    }

    public function test_customer_cannot_manage_packages(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/packages')->assertForbidden();
        $this->asPanelUser($customer)->post('/packages', [])->assertForbidden();
    }

    public function test_create_package_stores_cpanel_limit_keys(): void
    {
        $root = $this->userWithRole('root');
        $list = FeatureList::query()->where('is_default', true)->firstOrFail();
        $payload = array_merge(PackageLimits::DEFAULTS, [
            'name' => 'business',
            'description' => '10 GB plan',
            'feature_list_id' => $list->id,
            'status' => 'active',
            'QUOTA' => 10240,
            'MAXPOP' => 50,
            'is_default' => '0',
        ]);

        $this->asPanelUser($root)->post('/packages', $payload)->assertRedirect(route('packages.index'));

        $pkg = Package::query()->where('name', 'business')->first();
        $this->assertNotNull($pkg);
        $this->assertSame(10240, $pkg->QUOTA);
        $this->assertSame(50, $pkg->MAXPOP);
        $this->assertSame($list->id, $pkg->feature_list_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'package.create', 'target_id' => $pkg->id]);
    }

    public function test_cannot_archive_default_or_in_use_package(): void
    {
        $root = $this->userWithRole('root');
        $default = Package::query()->where('is_default', true)->firstOrFail();
        $this->asPanelUser($root)->post("/packages/{$default->id}/archive")->assertSessionHasErrors('status');

        $pkg = Package::query()->create(array_merge(PackageLimits::DEFAULTS, [
            'name' => 'used', 'status' => 'active', 'is_default' => false,
        ]));
        Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'username' => 'liveacct',
            'main_domain' => 'live.example.com',
            'contact_email' => 'a@example.com',
            'home_path' => '/home/liveacct',
            'status' => 'active',
            'quota_mb' => 100,
        ]);
        $this->asPanelUser($root)->post("/packages/{$pkg->id}/archive")->assertSessionHasErrors('status');
    }

    public function test_upgrade_and_quota_enqueue_setquota_task(): void
    {
        $root = $this->userWithRole('root');
        $default = Package::query()->where('name', 'default')->firstOrFail();
        $pro = Package::query()->create(array_merge(PackageLimits::DEFAULTS, [
            'name' => 'pro', 'status' => 'active', 'is_default' => false, 'QUOTA' => 5120,
        ]));
        $account = Account::query()->create([
            'server_id' => 1,
            'package_id' => $default->id,
            'username' => 'bobhost',
            'main_domain' => 'bob.example.com',
            'contact_email' => 'bob@example.com',
            'home_path' => '/home/bobhost',
            'status' => 'active',
            'quota_mb' => 1024,
        ]);

        $this->asPanelUser($root)->post("/accounts/{$account->id}/upgrade", [
            'package_id' => $pro->id,
        ])->assertRedirect();
        $account->refresh();
        $this->assertSame($pro->id, $account->package_id);
        $this->assertSame(5120, $account->quota_mb);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'account.setQuota')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame(5120, $payload['quota_mb']);

        $this->asPanelUser($root)->post("/accounts/{$account->id}/quota", [
            'quota_mb' => 200,
        ])->assertRedirect();
        $this->assertSame(200, $account->fresh()->quota_mb);
    }

    public function test_reseller_sees_global_and_own_packages_but_not_another_resellers(): void
    {
        $reseller = $this->userWithRole('reseller');
        $role = Role::query()->where('name', 'reseller')->firstOrFail();
        $other = User::query()->create([
            'username' => 'other_reseller',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $ownPackage = Package::query()->create([
            'owner_id' => $reseller->id, 'name' => 'my-plan', 'QUOTA' => 2048, 'status' => 'active',
        ]);
        $foreignPackage = Package::query()->create([
            'owner_id' => $other->id, 'name' => 'private-plan', 'QUOTA' => 4096, 'status' => 'active',
        ]);
        RolePermission::query()->create([
            'role_id' => $role->id,
            'permission_key' => 'packages.manage',
        ]);

        $this->asPanelUser($reseller)->get('/packages')
            ->assertOk()
            ->assertSee('default')
            ->assertSee('my-plan')
            ->assertDontSee('private-plan');
        $this->asPanelUser($reseller)->get('/packages/' . $ownPackage->id . '/edit')->assertOk();
        $this->asPanelUser($reseller)->get('/packages/' . $foreignPackage->id . '/edit')->assertNotFound();
        $this->asPanelUser($reseller)->put('/packages/' . $foreignPackage->id, array_merge(PackageLimits::DEFAULTS, [
            'name' => 'private-plan', 'status' => 'active',
        ]))->assertNotFound();
        $this->asPanelUser($reseller)->post('/packages/' . $foreignPackage->id . '/archive')->assertNotFound();
        $this->assertSame('active', $foreignPackage->fresh()->status);
        $this->asPanelUser($reseller)->get('/resellers')->assertForbidden();
    }

    public function test_reseller_package_create_records_owner_and_cannot_set_global_default(): void
    {
        $reseller = $this->userWithRole('reseller');
        RolePermission::query()->create([
            'role_id' => $reseller->role_id,
            'permission_key' => 'packages.manage',
        ]);

        $payload = array_merge(PackageLimits::DEFAULTS, [
            'name' => 'reseller-plan',
            'status' => 'active',
            'is_default' => '1',
        ]);
        $this->asPanelUser($reseller)->post('/packages', $payload)
            ->assertRedirect(route('packages.index'));

        $package = Package::query()->where('name', 'reseller-plan')->firstOrFail();
        $this->assertSame($reseller->id, (int) $package->owner_id);
        $this->assertFalse((bool) $package->is_default);
        $this->assertTrue((bool) Package::query()->where('name', 'default')->value('is_default'));
    }

    public function test_customer_cannot_upgrade(): void
    {
        $customer = $this->userWithRole('user');
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        $account = Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'username' => 'custhost',
            'main_domain' => 'cust.example.com',
            'contact_email' => 'c@example.com',
            'home_path' => '/home/custhost',
            'status' => 'active',
            'quota_mb' => 100,
        ]);
        $this->asPanelUser($customer)->post("/accounts/{$account->id}/upgrade", [
            'package_id' => $pkg->id,
        ])->assertForbidden();
    }
}
