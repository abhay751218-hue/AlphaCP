<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The rescue path when an admin is locked out. If this breaks, a customer with
 * a lost password has no way back into their own panel.
 */
class AdminPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $overrides = []): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'root'],
            ['label' => 'Root Admin', 'level' => 1, 'is_system' => true],
        );

        return User::query()->create([
            'username'      => 'admin',
            'password_hash' => Hash::make('OldPassword1'),
            'role_id'       => $role->id,
            'status'        => 'active',
            ...$overrides,
        ]);
    }

    public function test_it_sets_a_new_password_and_clears_lockouts(): void
    {
        $user = $this->admin()->forceFill([
            'failed_logins' => 5,
            'locked_until'  => now()->addMinutes(10),
        ]);
        $user->save();

        $this->artisan('alphacp:admin-password', ['username' => 'admin', '--password' => 'BrandNew1Pass'])
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check('BrandNew1Pass', $user->password_hash));
        $this->assertFalse(Hash::check('OldPassword1', $user->password_hash));
        $this->assertSame(0, $user->failed_logins);
        $this->assertNull($user->locked_until);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.password_reset', 'severity' => 'warning']);
    }

    public function test_it_can_disable_two_factor_when_the_phone_is_lost(): void
    {
        $this->admin(['two_factor_enabled' => true, 'two_factor_secret' => 'encrypted-blob']);

        $this->artisan('alphacp:admin-password', [
            'username' => 'admin', '--password' => 'BrandNew1Pass', '--reset-2fa' => true,
        ])->assertSuccessful();

        $user = User::query()->where('username', 'admin')->firstOrFail();
        $this->assertFalse($user->two_factor_enabled);
        $this->assertNull($user->two_factor_secret);
    }

    public function test_it_can_request_a_password_change_on_next_login(): void
    {
        $this->admin();

        $this->artisan('alphacp:admin-password', [
            'username' => 'admin', '--password' => 'BrandNew1Pass', '--force-change' => true,
        ])->assertSuccessful();

        $this->assertTrue(User::query()->where('username', 'admin')->firstOrFail()->force_password_change);
    }

    public function test_it_generates_a_password_when_none_is_given(): void
    {
        $this->admin();

        $exit = \Illuminate\Support\Facades\Artisan::call('alphacp:admin-password', ['username' => 'admin']);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/password\s*:\s*\S{16,}/', $output);

        preg_match('/password\s*:\s*(\S+)/', $output, $m);
        $hash = User::query()->where('username', 'admin')->firstOrFail()->password_hash;
        $this->assertTrue(Hash::check($m[1], $hash), 'the printed password must be the one that works');
    }

    public function test_it_refuses_a_weak_password(): void
    {
        $this->admin();

        $this->artisan('alphacp:admin-password', ['username' => 'admin', '--password' => 'abc'])
            ->assertFailed();
    }

    public function test_it_fails_cleanly_for_an_unknown_user(): void
    {
        $this->admin();

        $this->artisan('alphacp:admin-password', ['username' => 'ghost', '--password' => 'BrandNew1Pass'])
            ->assertFailed();
    }
}
