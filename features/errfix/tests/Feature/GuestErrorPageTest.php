<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Logged-out visitor ko error page par 500 crash nahi milna chahiye.
 * errors/404.blade.php → layouts.panel extend karta hai, aur us header me
 * `auth()->user()->username` tha — guest ke liye null → fatal.
 * Customer-facing panel me ye "wrong URL = white 500" tha.
 */
class GuestErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_a_404_page_instead_of_a_crash(): void
    {
        $this->get('/no-such-page-anywhere')->assertStatus(404)->assertSee('404');
    }

    public function test_guest_gets_a_clean_error_for_get_on_the_login_post_route(): void
    {
        $response = $this->get('/login');

        $this->assertNotSame(200, $response->status());
        $this->assertLessThan(500, $response->status(), 'guest ko 5xx crash nahi milna chahiye');
    }

    public function test_signed_in_user_error_page_is_also_standalone(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'root'],
            ['label' => 'Root Admin', 'level' => 1, 'is_system' => true],
        );

        $user = User::query()->create([
            'username'      => 'ownerx',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);

        $this->withSession(['two_factor_passed' => true])
            ->actingAs($user)
            ->get('/no-such-page-anywhere')
            ->assertStatus(404)
            ->assertSee('Panel login');
    }
}
