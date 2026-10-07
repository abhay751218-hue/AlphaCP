<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiTokensTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $role = Role::query()->where('name', 'root')->firstOrFail();

        return User::query()->create([
            'username'      => 'tokenroot',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asRoot(User $user): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($user->fresh());
    }

    public function test_tokens_page_renders(): void
    {
        $this->asRoot($this->root())->get('/api-tokens')->assertOk()->assertSee('API Tokens');
    }

    public function test_generate_token_shows_plain_once(): void
    {
        $r = $this->asRoot($this->root())
            ->post('/api-tokens', ['name' => 'billing'])
            ->assertRedirect('/api-tokens');

        $plain = session('new_token');
        $this->assertNotEmpty($plain);
        $this->assertStringStartsWith('acp_', $plain);
        $this->assertDatabaseHas('api_tokens', ['name' => 'billing']);
    }

    public function test_revoke_own_token(): void
    {
        $user  = $this->root();
        $token = ApiToken::query()->create(['user_id' => $user->id, 'name' => 'x', 'token_hash' => hash('sha256', 't')]);

        $this->asRoot($user)->delete('/api-tokens/' . $token->id)->assertRedirect('/api-tokens');
        $this->assertDatabaseCount('api_tokens', 0);
    }
}
