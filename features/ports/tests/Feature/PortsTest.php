<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortConfig;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->file = sys_get_temp_dir() . '/ports_' . uniqid() . '.json';
        config(['acp.ports_file' => $this->file]);
    }

    private function root(): User
    {
        $role = Role::query()->where('name', 'root')->firstOrFail();

        return User::query()->create([
            'username'      => 'portroot',
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
        $this->asRoot($this->root())->get('/ports')->assertOk()->assertSee('Ports Config');
    }

    public function test_save_writes_db_and_file(): void
    {
        $this->asRoot($this->root())->post('/ports', ['cpanel' => '1', 'custom' => '8443 99999 80'])
            ->assertRedirect('/ports');

        $cfg = PortConfig::query()->first()->data;
        $this->assertContains(8090, $cfg['ssl']);            // primary hamesha
        $this->assertContains(2083, $cfg['ssl']);            // cPanel ssl
        $this->assertContains(8443, $cfg['ssl']);            // valid custom
        $this->assertNotContains(99999, $cfg['ssl']);        // out of range
        $this->assertNotContains(80, $cfg['ssl']);           // privileged
        $this->assertSame([2082, 2086, 2095], $cfg['http']);

        $this->assertFileExists($this->file);
        $onDisk = json_decode((string) file_get_contents($this->file), true);
        $this->assertSame($cfg['ssl'], $onDisk['ssl']);
    }
}
