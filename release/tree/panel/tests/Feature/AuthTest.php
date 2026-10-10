<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'user'],
            ['label' => 'Hosting Customer', 'level' => 3, 'is_system' => true],
        );

        return User::query()->create([
            'username'      => 'tester',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
            ...$overrides,
        ]);
    }

    public function test_login_page_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('Panel Login');
    }

    public function test_valid_credentials_reach_the_dashboard(): void
    {
        $this->makeUser();

        $this->post('/login', ['username' => 'tester', 'password' => 'CorrectHorse1'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $this->makeUser();

        $this->post('/login', ['username' => 'tester', 'password' => 'nope'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertDatabaseHas('login_attempts', ['username' => 'tester', 'success' => 0]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_unknown_user_is_rejected(): void
    {
        $this->post('/login', ['username' => 'ghost', 'password' => 'whatever'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $this->makeUser(['status' => 'suspended']);

        $this->post('/login', ['username' => 'tester', 'password' => 'CorrectHorse1'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_locked_user_is_told_to_wait(): void
    {
        // locked_until is deliberately NOT mass-assignable — set it explicitly.
        $this->makeUser()->forceFill(['locked_until' => now()->addMinutes(10)])->save();

        $this->post('/login', ['username' => 'tester', 'password' => 'CorrectHorse1'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_two_factor_gate_blocks_the_dashboard(): void
    {
        $this->makeUser(['two_factor_enabled' => true, 'two_factor_secret' => 'encrypted-later']);

        $this->post('/login', ['username' => 'tester', 'password' => 'CorrectHorse1'])
            ->assertRedirect(route('twofactor.challenge'));

        $this->get('/dashboard')->assertRedirect(route('twofactor.challenge'));
    }
}
