<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResellersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $username, string $roleName): User
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::query()->create([
            'username'      => $username,
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asUser(User $user): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($user->fresh());
    }

    public function test_index_lists_resellers(): void
    {
        $root = $this->makeUser('rroot', 'root');
        $this->makeUser('res1', 'reseller');

        $this->asUser($root)->get('/resellers')
            ->assertOk()
            ->assertSee('Reseller Center')
            ->assertSee('res1');
    }

    public function test_promote_user_to_reseller(): void
    {
        $root = $this->makeUser('rroot', 'root');
        $u    = $this->makeUser('cand', 'user');

        $this->asUser($root)->post('/resellers', ['user_id' => $u->id])->assertRedirect('/resellers');

        $this->assertSame('reseller', $u->fresh()->role?->name);
    }

    public function test_demote_reseller_to_user(): void
    {
        $root = $this->makeUser('rroot', 'root');
        $res  = $this->makeUser('resx', 'reseller');

        $this->asUser($root)->delete('/resellers/' . $res->id)->assertRedirect('/resellers');

        $this->assertSame('user', $res->fresh()->role?->name);
    }

    public function test_update_privileges_syncs_role_permissions(): void
    {
        $root = $this->makeUser('rroot', 'root');

        $this->asUser($root)->post('/resellers/privileges', ['permissions' => ['accounts.create']])
            ->assertRedirect('/resellers');

        $role = Role::query()->where('name', 'reseller')->firstOrFail();
        $keys = $role->permissions()->pluck('permission_key')->all();

        $this->assertContains('accounts.create', $keys);
        $this->assertContains('core.access', $keys);
        $this->assertNotContains('accounts.terminate', $keys);
    }

    public function test_non_privileged_user_forbidden(): void
    {
        $u = $this->makeUser('plain', 'user');

        $this->asUser($u)->post('/resellers', ['user_id' => $u->id])->assertForbidden();
    }
}
