<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Domain;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Demo/test accounts — teeno persona (reseller, hosting customer, email-only).
 * Ye accounts testing ke liye hain: agar command toota to owner naye panel ko
 * login karke test hi nahi kar payega.
 */
class DemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const PASS = 'DemoPassw0rd!23';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_creates_all_three_personas_with_a_hosting_account(): void
    {
        $this->assertSame(0, $this->artisan('alphacp:demo-accounts', [
            '--password' => self::PASS,
            '--domain'   => 'customer1.test',
        ]));

        foreach ([['demoresel', 'reseller'], ['democust', 'user'], ['demomail', 'mail']] as [$username, $role]) {
            $user = User::query()->where('username', $username)->first();
            $this->assertNotNull($user, "user {$username} missing");
            $this->assertSame($role, $user->role?->name);
            $this->assertSame('active', $user->status);
            $this->assertFalse((bool) $user->force_password_change);
            $this->assertTrue(Hash::check(self::PASS, $user->password_hash));
        }

        $customer = User::query()->where('username', 'democust')->firstOrFail();

        $account = Account::query()->where('username', 'democust')->first();
        $this->assertNotNull($account);
        $this->assertSame('customer1.test', $account->main_domain);
        $this->assertSame($customer->id, $account->owner_user_id);
        $this->assertSame('active', $account->status);
        $this->assertSame('demo@customer1.test', $account->contact_email);

        $this->assertDatabaseHas('account_users', [
            'account_id' => $account->id,
            'user_id'    => $customer->id,
            'role'       => 'owner',
        ]);

        // Main domain row (Domains page) bhi seed hoti hai.
        $this->assertSame(1, Domain::query()->where('domain', 'customer1.test')->count());

        $this->assertDatabaseHas('audit_logs', ['action' => 'demo.accounts']);
    }

    public function test_the_demo_customer_can_actually_log_in(): void
    {
        $this->assertSame(0, $this->artisan('alphacp:demo-accounts', ['--password' => self::PASS]));

        $this->post('/login', ['username' => 'democust', 'password' => self::PASS])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs(User::query()->where('username', 'democust')->firstOrFail());
    }

    public function test_it_is_idempotent_and_keeps_existing_passwords(): void
    {
        $this->assertSame(0, $this->artisan('alphacp:demo-accounts', ['--password' => self::PASS]));
        $this->assertSame(0, $this->artisan('alphacp:demo-accounts', ['--password' => 'Other1Passw0rd!']));

        $this->assertSame(1, User::query()->where('username', 'democust')->count());
        $this->assertSame(1, Account::query()->where('username', 'democust')->count());

        // Doosri run me password nahi badla (sirf --reset-password se badalta hai).
        $this->assertTrue(Hash::check(self::PASS, User::query()->where('username', 'democust')->firstOrFail()->password_hash));

        $this->assertSame(0, $this->artisan('alphacp:demo-accounts', [
            '--password' => 'Other1Passw0rd!', '--reset-password' => true,
        ]));

        $this->assertTrue(Hash::check('Other1Passw0rd!', User::query()->where('username', 'democust')->firstOrFail()->password_hash));
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->assertNotSame(0, $this->artisan('alphacp:demo-accounts', ['--password' => 'abc']));

        $this->assertSame(0, User::query()->where('username', 'democust')->count());
    }

    public function test_it_seeds_roles_when_they_are_missing(): void
    {
        Role::query()->delete();
        User::query()->delete();

        $this->assertSame(0, $this->artisan('alphacp:demo-accounts', ['--password' => self::PASS]));

        $this->assertSame(4, Role::query()->whereIn('name', ['root', 'reseller', 'user', 'mail'])->count());
        $this->assertSame(3, User::query()->count());
    }
}
