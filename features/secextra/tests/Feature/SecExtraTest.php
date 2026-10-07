<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SecurityExtra;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecExtraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function root(): User
    {
        $role = Role::query()->where('name', 'root')->firstOrFail();

        return User::query()->create([
            'username'      => 'secroot',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asRoot(User $u): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($u->fresh());
    }

    public function test_hotlink_page_renders(): void
    {
        $this->asRoot($this->root())->get('/hotlink-protection')->assertOk()->assertSee('Hotlink Protection');
    }

    public function test_leech_page_renders(): void
    {
        $this->asRoot($this->root())->get('/leech-protection')->assertOk()->assertSee('Leech Protection');
    }

    public function test_save_hotlink(): void
    {
        $u = $this->root();
        $this->asRoot($u)->post('/security-extras', [
            'kind'         => 'hotlink',
            'enabled'      => '1',
            'allowed'      => "a.com\nb.com",
            'allow_direct' => '1',
        ])->assertRedirect('/hotlink-protection');

        $rec = SecurityExtra::query()->where('user_id', $u->id)->where('kind', 'hotlink')->first();
        $this->assertTrue($rec->data['enabled']);
        $this->assertSame(['a.com', 'b.com'], $rec->data['allowed']);
    }

    public function test_save_leech_clamps(): void
    {
        $u = $this->root();
        $this->asRoot($u)->post('/security-extras', [
            'kind'       => 'leech',
            'enabled'    => '1',
            'max_logins' => 999,
            'action'     => 'block',
        ])->assertRedirect('/leech-protection');

        $rec = SecurityExtra::query()->where('user_id', $u->id)->where('kind', 'leech')->first();
        $this->assertSame(20, $rec->data['max_logins']); // clamped
    }
}
