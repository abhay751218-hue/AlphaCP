<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Error pages ab STANDALONE hain (no layout/DB/auth) — galat URL, 405, 403
 * kabhi layout-crash se 500 nahi denge. Production me /login par 500 isi
 * layout-dependency se aata tha.
 */
class ErrorPagesStandaloneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $username, string $role): User
    {
        return User::query()->create([
            'username'      => $username,
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => Role::query()->where('name', $role)->firstOrFail()->id,
            'status'        => 'active',
        ]);
    }

    public function test_guest_wrong_url_gets_standalone_404(): void
    {
        $this->get('/definitely-not-a-page')
            ->assertStatus(404)
            ->assertSee('Panel login')
            ->assertDontSee('Logout'); // layout use nahi ho raha
    }

    public function test_guest_get_login_never_500s(): void
    {
        // Production 500 wala exact URL — fallback ise 404 deta hai,
        // standalone page ke saath (layout-crash se kabhi 500 nahi).
        $this->get('/login')
            ->assertStatus(404)
            ->assertSee('404')
            ->assertDontSee('Server Error');
    }

    public function test_authenticated_user_wrong_url_gets_standalone_404(): void
    {
        $root = $this->makeUser('rootman', 'root');

        $this->withSession(['two_factor_passed' => true])
            ->actingAs($root)
            ->get('/definitely-not-a-page')
            ->assertStatus(404)
            ->assertSee('Panel login');
    }

    public function test_forbidden_page_gives_standalone_403(): void
    {
        $cust = $this->makeUser('custone', 'user');

        $this->withSession(['two_factor_passed' => true])
            ->actingAs($cust)
            ->get('/system')
            ->assertStatus(403)
            ->assertSee('403');
    }
}
