<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OptimizeSetting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FileXtrasTest extends TestCase
{
    use RefreshDatabase;

    private string $images;
    private string $trash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->images = sys_get_temp_dir() . '/img_' . uniqid();
        $this->trash  = sys_get_temp_dir() . '/trash_' . uniqid();
        mkdir($this->images, 0777, true);
        mkdir($this->trash, 0777, true);
        config(['acp.images_base' => $this->images, 'acp.trash_base' => $this->trash]);
    }

    private function root(): User
    {
        $role = Role::query()->where('name', 'root')->firstOrFail();

        return User::query()->create([
            'username'      => 'fxroot',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asRoot(User $u): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($u->fresh());
    }

    public function test_optimize_save(): void
    {
        $u = $this->root();
        $this->asRoot($u)->post('/optimize-website', ['level' => 'all'])->assertRedirect('/optimize-website');
        $this->assertDatabaseHas('optimize_settings', ['user_id' => $u->id, 'level' => 'all']);
    }

    public function test_images_lists_only_images(): void
    {
        file_put_contents($this->images . '/logo.png', 'x');
        file_put_contents($this->images . '/notes.txt', 'x');

        $this->asRoot($this->root())->get('/images')
            ->assertOk()
            ->assertSee('logo.png')
            ->assertDontSee('notes.txt');
    }

    public function test_trash_delete(): void
    {
        file_put_contents($this->trash . '/old.txt', 'x');

        $this->asRoot($this->root())->delete('/trash/old.txt')->assertRedirect('/trash');
        $this->assertFileDoesNotExist($this->trash . '/old.txt');
    }

    public function test_trash_blocks_traversal(): void
    {
        file_put_contents($this->trash . '/keep.txt', 'x');
        // encoded slash route-level par reject (404/405) — file safe rehni chahiye
        $this->asRoot($this->root())->delete('/trash/..%2Fkeep.txt');
        $this->assertFileExists($this->trash . '/keep.txt');
    }
}
