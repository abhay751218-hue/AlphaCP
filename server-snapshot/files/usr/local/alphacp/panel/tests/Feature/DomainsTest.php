<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DomainsTest extends TestCase
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

    public function test_customer_can_open_domains_and_sees_main(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/domains')
            ->assertOk()
            ->assertSee('shop.example.com')
            ->assertSee('main');
        $this->assertDatabaseHas('domains', ['domain' => 'shop.example.com', 'type' => 'main']);
    }

    public function test_customer_can_add_addon_and_subdomain(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/domains', [
            'type' => 'addon',
            'domain' => 'blogsite.example.com',
        ])->assertRedirect(route('domains.index'));

        $row = Domain::query()->where('domain', 'blogsite.example.com')->first();
        $this->assertNotNull($row);
        $this->assertSame('addon', $row->type);
        $this->assertSame('/home/custhost/blogsite.example.com/public_html', $row->document_root);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'domain.add')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('addon', $payload['type']);
        $this->assertSame('custhost', $payload['username']);

        $this->asPanelUser($customer)->post('/domains', [
            'type' => 'sub',
            'domain' => 'blog.shop.example.com',
        ])->assertRedirect();
        $sub = Domain::query()->where('domain', 'blog.shop.example.com')->first();
        $this->assertSame('/home/custhost/public_html/blog', $sub->document_root);
    }

    public function test_subdomain_must_be_under_main(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/domains', [
            'type' => 'sub',
            'domain' => 'other.example.net',
        ])->assertSessionHasErrors('domain');
    }

    public function test_cannot_delete_main_domain(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/domains')->assertOk();
        $main = Domain::query()->where('type', 'main')->firstOrFail();
        $this->asPanelUser($customer)->delete('/domains/' . $main->id)->assertSessionHasErrors('domain');
    }

    public function test_customer_cannot_touch_someone_elses_domain(): void
    {
        [$customer] = $this->customerWithAccount();
        $role = Role::query()->where('name', 'user')->firstOrFail();
        $other = User::query()->create([
            'username' => 'u_othercust',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        $acct2 = Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'owner_user_id' => $other->id,
            'username' => 'otherhost',
            'main_domain' => 'other.example.com',
            'contact_email' => 'o@example.com',
            'home_path' => '/home/otherhost',
            'status' => 'active',
            'quota_mb' => 100,
        ]);
        $stolen = Domain::query()->create([
            'account_id' => $acct2->id,
            'type' => 'addon',
            'domain' => 'taken.example.com',
            'document_root' => '/home/otherhost/taken.example.com/public_html',
            'status' => 'active',
        ]);
        $this->asPanelUser($customer)->delete('/domains/' . $stolen->id)->assertForbidden();
    }

    public function test_whm_user_does_not_use_customer_domain_form(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/domains')->assertOk()->assertSee('Account Panel');
        $this->asPanelUser($root)->post('/domains', [
            'type' => 'addon',
            'domain' => 'nope.example.com',
        ])->assertForbidden();
    }

    public function test_respects_maxaddon_limit(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $account->package->forceFill(['MAXADDON' => 0])->save();
        $this->asPanelUser($customer)->post('/domains', [
            'type' => 'addon',
            'domain' => 'extra.example.com',
        ])->assertSessionHasErrors('domain');
    }
}
