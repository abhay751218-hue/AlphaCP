<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class G5Test extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->base = sys_get_temp_dir() . '/g5_' . uniqid();
        mkdir($this->base, 0777, true);
        config(['acp.git_base' => $this->base]);
    }

    private function root(): User
    {
        $role = Role::query()->where('name', 'root')->firstOrFail();

        return User::query()->create([
            'username'      => 'g5root',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asRoot(User $u): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($u->fresh());
    }

    public function test_git_index_renders(): void
    {
        $this->asRoot($this->root())->get('/git')->assertOk()->assertSee('Git Version Control');
    }

    public function test_git_clone_runs_git(): void
    {
        Process::fake();

        $this->asRoot($this->root())
            ->post('/git/clone', ['url' => 'https://example.com/r.git', 'dir' => 'myrepo'])
            ->assertRedirect('/git');

        Process::assertRan(fn ($p) => str_contains($p->command, 'clone'));
    }

    public function test_git_status_shows_output(): void
    {
        mkdir($this->base . '/myrepo', 0777, true);
        Process::fake(fn () => Process::result("M app/x.php\n"));

        $this->asRoot($this->root())
            ->get('/git/status/myrepo')
            ->assertOk()
            ->assertSee('app/x.php');
    }

    public function test_git_status_nonexistent_redirects(): void
    {
        $this->asRoot($this->root())
            ->get('/git/status/nope')
            ->assertRedirect('/git');
    }

    public function test_git_status_blocks_traversal(): void
    {
        // encoded slash route-level par hi reject hota hai (404) — traversal blocked
        $this->asRoot($this->root())
            ->get('/git/status/..%2Fetc')
            ->assertNotFound();
    }

    public function test_terminal_runs_whitelisted(): void
    {
        Process::fake(fn () => Process::result("file1\nfile2\n"));

        $this->asRoot($this->root())
            ->post('/terminal', ['command' => 'ls'])
            ->assertRedirect('/terminal');

        Process::assertRan(fn ($p) => str_contains($p->command, 'ls'));
        $this->assertNotNull(session('term_output'));
    }

    public function test_terminal_blocks_dangerous(): void
    {
        Process::fake();

        $this->asRoot($this->root())
            ->post('/terminal', ['command' => 'rm -rf /'])
            ->assertRedirect('/terminal');

        Process::assertNotRan(fn ($p) => str_contains($p->command, 'rm'));
        $this->assertNotNull(session('term_error'));
    }
}
