<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SESSION-AUTH regression tests — installer/login-fix.sh v1.0 ke saath aaye.
 *
 * Ye suite isliye zaroori hai kyunki panel ke baaki tests `actingAs()` use karte
 * hain, jo guard par user SEEDHA set kar deta hai. Asli browser me user session
 * se `EloquentUserProvider::retrieveById()` ke zariye load hota hai — aur us
 * query par global scopes lagte hain. `ResellerScopeProvider` v1 scope ke andar
 * `Auth::user()` call karta tha, jo khud `retrieveById()` trigger karta tha:
 *
 *     Auth::user() -> SessionGuard::user() -> retrieveById()
 *       -> User query -> reseller_scope -> Auth::user() -> ... (infinite)
 *
 * Nateeja: login POST 302 deta tha, phir pehla hi authenticated request PHP
 * fatal (memory/nesting) -> HTTP 500. User ko yahi dikhta tha: "login page
 * khulta hai, credentials daalne par login nahi hota, error aata hai."
 *
 * `freshRequest()` niche wahi asli haalat banata hai: guards bhula do, taaki
 * user session se dobara resolve ho.
 */
class SessionAuthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private const LEVELS = ['root' => 1, 'reseller' => 2, 'user' => 3, 'mail' => 4];

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['label' => ucfirst($name), 'level' => self::LEVELS[$name] ?? 3, 'is_system' => true],
        );
    }

    private function makeUser(string $roleName, string $username, ?int $createdBy = null): User
    {
        return User::query()->create([
            'username'              => $username,
            'email'                 => $username . '@example.test',
            'password_hash'         => Hash::make('CorrectHorse1'),
            'full_name'             => ucfirst($username),
            'role_id'               => $this->role($roleName)->id,
            'status'                => 'active',
            'force_password_change' => false,
            'two_factor_enabled'    => false,
            'created_by'            => $createdBy,
        ]);
    }

    /** Reseller ko scoping wale permission keys do (PermissionCatalog se independent). */
    private function grantResellerScope(): void
    {
        foreach (['accounts.view', 'users.view'] as $key) {
            RolePermission::query()->firstOrCreate([
                'role_id'        => $this->role('reseller')->id,
                'permission_key' => $key,
            ]);
        }
    }

    /**
     * Browser jaisa ASLI request: guards bhool jao taaki user session se
     * (retrieveById + global scopes ke saath) resolve ho.
     */
    private function freshRequest(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get($url);
    }

    // ------------------------------------------------- B5: fatal recursion

    #[DataProvider('rolesProvider')]
    public function test_authenticated_page_works_on_a_fresh_request(string $roleName): void
    {
        $this->grantResellerScope();
        config()->set('acp.entry_ports_file', '/nonexistent/acp-entry-ports.json');

        $this->makeUser($roleName, $roleName . 'u');

        $this->post('https://panel.test:8090/login', [
            'username' => $roleName . 'u',
            'password' => 'CorrectHorse1',
        ])->assertRedirect();

        $this->assertTrue($this->app['auth']->guard('web')->check(), 'login ke baad guard authenticated hona chahiye');

        // /security/password = sabse halka authenticated page (auth + 2fa + password.fresh)
        $response = $this->freshRequest('https://panel.test:8090/security/password');

        $this->assertNotSame(500, $response->status(), 'global-scope recursion se 500 (ResellerScopeProvider v1 bug)');
        $this->assertSame(200, $response->status(), 'session se user resolve hoke page 200 dena chahiye');
    }

    /** @return array<string, array{0: string}> */
    public static function rolesProvider(): array
    {
        return [
            'root'     => ['root'],
            'reseller' => ['reseller'],
            'user'     => ['user'],
            'mail'     => ['mail'],
        ];
    }

    public function test_dashboard_also_survives_a_fresh_request(): void
    {
        $this->grantResellerScope();
        config()->set('acp.entry_ports_file', '/nonexistent/acp-entry-ports.json');
        $this->makeUser('root', 'rootu');

        $this->post('https://panel.test:8090/login', ['username' => 'rootu', 'password' => 'CorrectHorse1']);
        $response = $this->freshRequest('https://panel.test:8090/dashboard');

        $this->assertNotSame(500, $response->status(), 'dashboard par 500 = recursion wapas aa gaya');
    }

    // ------------------------------------------------- scoping abhi bhi sahi

    public function test_reseller_user_scope_still_applies(): void
    {
        $this->grantResellerScope();

        $reseller = $this->makeUser('reseller', 'res1');
        $this->makeUser('user', 'mine', $reseller->id);
        $this->makeUser('user', 'theirs');

        $this->actingAs($reseller->fresh())->withSession(['two_factor_passed' => true])->get('/users')->assertOk();

        $visible = User::query()->pluck('username')->all();

        $this->assertContains('res1', $visible, 'reseller khud ko dekh sakta hai');
        $this->assertContains('mine', $visible, 'apna banaya hua user dikhta hai');
        $this->assertNotContains('theirs', $visible, 'doosre ka user nahi dikhna chahiye');
        $this->assertSame(3, User::query()->withoutGlobalScope('reseller_scope')->count(),
            'scope hataane par teeno users maujood hain (yaani scope hi chhupa raha hai)');
    }

    public function test_root_sees_every_user(): void
    {
        $this->grantResellerScope();

        $root = $this->makeUser('root', 'rootu');
        $this->makeUser('reseller', 'res1', $root->id);
        $this->makeUser('user', 'cust1');

        $this->actingAs($root->fresh())->withSession(['two_factor_passed' => true])->get('/users')->assertOk();

        $this->assertSame(
            ['cust1', 'res1', 'rootu'],
            User::query()->orderBy('username')->pluck('username')->all(),
            'root par scope laagu nahi hota',
        );
    }

    public function test_account_scope_does_not_break_queries(): void
    {
        $this->grantResellerScope();
        $reseller = $this->makeUser('reseller', 'res1');

        $this->actingAs($reseller->fresh())->withSession(['two_factor_passed' => true])->get('/users')->assertOk();

        $this->assertSame(0, Account::query()->count(), 'khaali accounts table par scope crash nahi karna chahiye');
    }

    public function test_guest_queries_are_unscoped(): void
    {
        $this->grantResellerScope();
        $this->makeUser('root', 'rootu');
        $this->makeUser('reseller', 'res1');
        $this->makeUser('user', 'cust1');

        // CLI / seeder / login-controller: koi actor nahi -> koi scope nahi
        $this->assertSame(3, User::query()->count());
    }

    // ------------------------------------------------- create guard (rule 3)

    public function test_reseller_cannot_create_equal_or_higher_role(): void
    {
        $this->grantResellerScope();
        $reseller = $this->makeUser('reseller', 'res1');
        $this->actingAs($reseller->fresh())->withSession(['two_factor_passed' => true]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->makeUser('reseller', 'res2', $reseller->id);
    }

    public function test_reseller_can_create_lower_role(): void
    {
        $this->grantResellerScope();
        $reseller = $this->makeUser('reseller', 'res1');
        $this->actingAs($reseller->fresh())->withSession(['two_factor_passed' => true]);

        $made = $this->makeUser('user', 'cust1', $reseller->id);

        $this->assertDatabaseHas('users', ['username' => 'cust1', 'created_by' => $reseller->id]);
        $this->assertSame($reseller->id, (int) $made->created_by);
    }

    public function test_root_can_create_any_role(): void
    {
        $this->grantResellerScope();
        $root = $this->makeUser('root', 'rootu');
        $this->actingAs($root->fresh())->withSession(['two_factor_passed' => true]);

        $this->makeUser('reseller', 'res1', $root->id);

        $this->assertDatabaseHas('users', ['username' => 'res1']);
    }
}
