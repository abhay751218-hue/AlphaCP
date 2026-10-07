<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * cPanel/WHM reseller parity: reseller sirf APNE accounts/users dekhe,
 * doosre ka account URL se bhi na khule, aur wo reseller/root role na bana paye.
 * Base controller me ye scoping nahi thi (sab accounts sabko dikhte the).
 */
class ResellerScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $root;
    private User $res1;
    private User $res2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->root = $this->makeUser('rootman', 'root');
        $this->res1 = $this->makeUser('resone', 'reseller');
        $this->res2 = $this->makeUser('restwo', 'reseller');
    }

    private function makeUser(string $username, string $roleName): User
    {
        return User::query()->create([
            'username'      => $username,
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => Role::query()->where('name', $roleName)->firstOrFail()->id,
            'status'        => 'active',
        ]);
    }

    private function makeAccount(string $username, string $domain, ?User $reseller): Account
    {
        $owner = $this->makeUser($username, 'user');

        return Account::query()->create([
            'server_id'     => 1,
            'package_id'    => Package::query()->where('name', 'default')->firstOrFail()->id,
            'reseller_id'   => $reseller?->id,
            'owner_user_id' => $owner->id,
            'username'      => $username,
            'main_domain'   => $domain,
            'contact_email' => 'x@' . $domain,
            'home_path'     => '/home/' . $username,
            'status'        => 'active',
        ]);
    }

    private function asUser(User $user): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($user->fresh());
    }

    public function test_reseller_sees_only_his_own_accounts(): void
    {
        $a1    = $this->makeAccount('custone', 'custone.test', $this->res1);
        $other = $this->makeAccount('custtwo', 'custtwo.test', $this->res2);

        $page = $this->asUser($this->res1)->get('/accounts');
        $page->assertOk()->assertSee('custone.test')->assertDontSee('custtwo.test');

        // Doosre reseller ka account URL se bhi 404 (binding bhi scoped hai).
        $this->asUser($this->res1)->get('/accounts/' . $a1->id)->assertOk();
        $this->asUser($this->res1)->get('/accounts/' . $other->id)->assertNotFound();
    }

    public function test_root_still_sees_every_account(): void
    {
        $this->makeAccount('custone', 'custone.test', $this->res1);
        $this->makeAccount('custtwo', 'custtwo.test', $this->res2);

        $this->asUser($this->root)->get('/accounts')
            ->assertOk()
            ->assertSee('custone.test')
            ->assertSee('custtwo.test');
    }

    public function test_reseller_user_list_is_scoped_to_his_customers(): void
    {
        $this->makeAccount('custone', 'custone.test', $this->res1);
        $this->makeAccount('custtwo', 'custtwo.test', $this->res2);

        $this->asUser($this->res1)->get('/users')
            ->assertOk()
            ->assertSee('custone')
            ->assertSee('resone')       // khud ko dekh sakta hai (auth self-include)
            ->assertDontSee('custtwo')
            ->assertDontSee('restwo');
    }

    public function test_reseller_cannot_create_reseller_or_root_users_but_can_create_customers(): void
    {
        $resellerRole = Role::query()->where('name', 'reseller')->firstOrFail();
        $rootRole     = Role::query()->where('name', 'root')->firstOrFail();
        $userRole     = Role::query()->where('name', 'user')->firstOrFail();

        $payload = fn (int $roleId) => [
            'username'  => 'newguy' . $roleId,
            'role_id'   => $roleId,
            'password'  => 'FreshPassw0rd!x',
        ];

        $this->asUser($this->res1)->post('/users', $payload($resellerRole->id))->assertForbidden();
        $this->asUser($this->res1)->post('/users', $payload($rootRole->id))->assertForbidden();

        $this->asUser($this->res1)->post('/users', $payload($userRole->id))
            ->assertRedirect(route('users.index'));

        $created = User::query()->where('username', 'newguy' . $userRole->id)->first();
        $this->assertNotNull($created);
        $this->assertSame($this->res1->id, $created->created_by);
    }

    public function test_reseller_session_auth_survives_the_user_scope(): void
    {
        // Global user-scope khud ko include na kare to login hi toot jaata —
        // isliye ye regression test zaroori hai.
        $this->post('/login', ['username' => 'resone', 'password' => 'CorrectHorse1'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->res1);
    }
}
