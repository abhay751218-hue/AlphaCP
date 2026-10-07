<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\WebDiskAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebDiskTest extends TestCase
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
            'username'      => 'wdroot',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asRoot(User $u): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($u->fresh());
    }

    public function test_index_renders(): void
    {
        $this->asRoot($this->root())->get('/webdisk')->assertOk()->assertSee('Web Disk');
    }

    public function test_create_account(): void
    {
        $u = $this->root();
        $this->asRoot($u)->post('/webdisk', ['login' => 'designer', 'permissions' => 'ro'])
            ->assertRedirect('/webdisk');

        $this->assertDatabaseHas('webdisk_accounts', ['user_id' => $u->id, 'login' => 'designer', 'permissions' => 'ro']);
    }

    public function test_delete_own_account(): void
    {
        $u = $this->root();
        $a = WebDiskAccount::query()->create(['user_id' => $u->id, 'login' => 'tmp', 'permissions' => 'rw']);

        $this->asRoot($u)->delete('/webdisk/' . $a->id)->assertRedirect('/webdisk');
        $this->assertDatabaseCount('webdisk_accounts', 0);
    }

    public function test_invalid_login_rejected(): void
    {
        $this->asRoot($this->root())
            ->from('/webdisk')
            ->post('/webdisk', ['login' => 'bad login!', 'permissions' => 'rw'])
            ->assertRedirect('/webdisk');

        $this->assertDatabaseCount('webdisk_accounts', 0);
    }
}
