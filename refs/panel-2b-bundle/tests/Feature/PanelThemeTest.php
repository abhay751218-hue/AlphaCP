<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Panel colour/identity contract — cPanel company jaisa 3 alag panels:
 *   whm     → Server Manager (admin)   — dark navy theme
 *   cpanel  → Account Panel (customer) — light theme
 *   webmail → Webmail                  — teal theme
 */
class PanelThemeTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::query()->create([
            'username'      => 'theme_' . $roleName,
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asPanelUser(User $user): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($user->fresh());
    }

    public function test_whm_dashboard_uses_dark_server_manager_theme(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('data-panel="whm"', false)
            ->assertSee('Server Manager · admin')
            // WHM jaisa left sidebar navigation
            ->assertSee('class="side"', false)
            ->assertSee('Create Account')
            ->assertSee('List Accounts');
    }

    public function test_customer_dashboard_uses_light_account_panel_theme(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('data-panel="cpanel"', false)
            ->assertSee('Account Panel')
            // customer ko WHM sidebar/side-server box kabhi nahi
            ->assertDontSee('class="side"', false);
    }

    public function test_webmail_page_uses_its_own_teal_theme(): void
    {
        $customer = $this->userWithRole('user');
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        \App\Models\Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'owner_user_id' => $customer->id,
            'username' => 'themedhost',
            'main_domain' => 'themed.example.com',
            'contact_email' => 't@example.com',
            'home_path' => '/home/themedhost',
            'status' => 'active',
            'quota_mb' => 100,
        ]);
        $this->asPanelUser($customer)->get('/webmail')
            ->assertOk()
            ->assertSee('data-panel="webmail"', false);
    }

    public function test_login_page_keeps_brand_theme(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('AlphaCP');
    }
}
