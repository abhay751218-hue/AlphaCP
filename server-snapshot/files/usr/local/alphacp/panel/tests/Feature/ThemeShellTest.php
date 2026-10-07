<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\ModuleCatalog;
use App\Support\Theme;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * cPanel-parity UI tests — theme engine (docs/10-ui-parity-design.md, Phase P-UI-1).
 *
 * Ye tests "design contract" ko lock karte hain:
 *   - root/reseller  → WHM look (nav-tree, Favorites, Statistics)
 *   - customer       → cPanel look (Tools grid, General Information + Statistics column, search)
 *   - mail role      → Webmail look
 *   - style switcher sirf apne mode ka theme allow karta hai
 */
class ThemeShellTest extends TestCase
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

    public function test_root_gets_whm_theme_shell(): void
    {
        $root = $this->userWithRole('root');

        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('theme-whm', escape: false)
            ->assertSee('Favorites')
            ->assertSee('Statistics')
            ->assertSee('Find functions quickly')
            ->assertSee('DNS Functions')
            ->assertSee('Server Status');
    }

    public function test_customer_gets_cpanel_theme_shell(): void
    {
        $customer = $this->userWithRole('user');

        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('theme-jupiter', escape: false)
            ->assertSee('General Information')
            ->assertSee('Find functions quickly')
            ->assertSee('Files')
            ->assertSee('Databases')
            ->assertSee('Email')
            ->assertDontSee('theme-whm', escape: false)
            ->assertDontSee('DNS Functions');
    }

    public function test_customer_right_column_shows_general_information_rows(): void
    {
        $customer = $this->userWithRole('user');
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'owner_user_id' => $customer->id,
            'username' => 'custhost',
            'main_domain' => 'theme.example.com',
            'contact_email' => 'c@example.com',
            'home_path' => '/home/custhost',
            'status' => 'active',
            'quota_mb' => 100,
        ]);

        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Current User')
            ->assertSee('Primary Domain')
            ->assertSee('theme.example.com')
            ->assertSee('Home Directory')
            ->assertSee('/home/custhost')
            ->assertSee('Last Login IP Address')
            ->assertSee('Statistics');
    }

    public function test_mail_role_gets_webmail_shell(): void
    {
        $mail = $this->userWithRole('mail');

        $this->asPanelUser($mail)->get('/dashboard')
            ->assertOk()
            ->assertSee('theme-webmail', escape: false)
            ->assertSee('Webmail');
    }

    public function test_theme_resolution_matches_role_mode(): void
    {
        $root = $this->userWithRole('root');
        $customer = $this->userWithRole('user');

        $this->assertSame(Theme::WHM, Theme::forUser($root));
        $this->assertSame(Theme::CPANEL, Theme::forUser($customer));
    }

    public function test_style_switcher_keeps_to_own_mode(): void
    {
        $customer = $this->userWithRole('user');

        $this->asPanelUser($customer)
            ->post('/preferences/style', ['theme' => Theme::WHM])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->asPanelUser($customer)
            ->post('/preferences/style', ['theme' => Theme::CPANEL])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Choice session me save hui — agla page usi theme me render hoga.
        $this->withSession(['two_factor_passed' => true, 'acp_theme' => Theme::CPANEL])
            ->actingAs($customer->fresh())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('theme-jupiter', escape: false);
    }

    public function test_search_index_contains_live_tools_with_urls(): void
    {
        $customer = $this->userWithRole('user');
        $index = Theme::searchIndex(ModuleCatalog::sectionsFor($customer));

        $byName = collect($index)->keyBy('name');
        $this->assertTrue($byName->has('File Manager'));
        $this->assertSame(route('files.index'), $byName['File Manager']['url']);

        // WHM tools customer ke index me nahi hone chahiye.
        $this->assertFalse($byName->has('Create a New Account'));
    }

    public function test_whm_search_index_has_whm_tools(): void
    {
        $root = $this->userWithRole('root');
        $index = Theme::searchIndex(ModuleCatalog::sectionsFor($root));

        $byName = collect($index)->keyBy('name');
        $this->assertTrue($byName->has('List Accounts'));
        $this->assertTrue($byName->has('DNS Zone Manager'));
    }
}
