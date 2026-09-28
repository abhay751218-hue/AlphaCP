<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::query()->create([
            'username'      => 'u_' . $roleName,
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    /**
     * Log in as $user with the 2FA gate satisfied (that gate has its own test
     * in AuthTest; here we test authorization, not the login flow).
     */
    private function asPanelUser(User $user): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($user->fresh());
    }

    public function test_root_can_see_users_and_audit(): void
    {
        $root = $this->userWithRole('root');

        $this->asPanelUser($root)->get('/users')->assertOk();
        $this->asPanelUser($root)->get('/audit')->assertOk();
        $this->asPanelUser($root)->get('/system')->assertOk();
    }

    public function test_customer_role_is_denied_admin_areas(): void
    {
        $customer = $this->userWithRole('user');

        $this->asPanelUser($customer)->get('/users')->assertForbidden();
        $this->asPanelUser($customer)->get('/audit')->assertForbidden();
        $this->asPanelUser($customer)->get('/system')->assertForbidden();
    }

    public function test_mail_only_role_can_only_reach_its_own_security_page(): void
    {
        $mail = $this->userWithRole('mail');

        $this->asPanelUser($mail)->get('/security')->assertOk();
        $this->asPanelUser($mail)->get('/users')->assertForbidden();
        $this->asPanelUser($mail)->get('/system')->assertForbidden();
    }

    public function test_root_bypasses_permission_checks_by_design(): void
    {
        $root = $this->userWithRole('root');
        // Deliberately remove every permission from the root role: level 1 must
        // still get through (Gate::before in AppServiceProvider).
        \App\Models\RolePermission::query()->where('role_id', $root->role_id)->delete();

        $this->asPanelUser($root)->get('/users')->assertOk();
    }

    public function test_unknown_route_returns_the_panel_404_page(): void
    {
        $root = $this->userWithRole('root');

        $this->asPanelUser($root)->get('/this/does/not/exist')->assertNotFound();
    }
}
