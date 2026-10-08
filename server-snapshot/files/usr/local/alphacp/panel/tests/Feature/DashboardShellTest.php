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

class DashboardShellTest extends TestCase
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

    public function test_root_sees_whm_not_cpanel_file_manager(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Server Manager Dashboard')
            ->assertSee('Create Account')
            ->assertSee('List Accounts')
            ->assertSee('Packages')
            ->assertDontSee('File Manager');
    }

    public function test_customer_sees_cpanel_not_account_create(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Account Panel')
            ->assertSee('Domains')
            ->assertDontSee('Create Account')
            ->assertDontSee('List Accounts')
            ->assertDontSee('Create a New Account');
    }

    public function test_customer_nav_has_no_accounts_link(): void
    {
        $customer = $this->userWithRole('user');
        $html = $this->asPanelUser($customer)->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('href="' . route('accounts.index', absolute: false), $html);
        $this->assertStringNotContainsString('href="' . route('accounts.create', absolute: false), $html);
        $this->assertStringNotContainsString('href="' . route('packages.index', absolute: false), $html);
    }

    public function test_mail_role_does_not_see_domains_or_whm(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Create Account')
            ->assertDontSee('Addon Domains');
    }

    public function test_customer_with_account_sees_primary_domain(): void
    {
        $customer = $this->userWithRole('user');
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'owner_user_id' => $customer->id,
            'username' => 'custhost',
            'main_domain' => 'cust.example.com',
            'contact_email' => 'c@example.com',
            'home_path' => '/home/custhost',
            'status' => 'active',
            'quota_mb' => 100,
        ]);
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('cust.example.com')
            ->assertDontSee('Create Account');
    }
}
