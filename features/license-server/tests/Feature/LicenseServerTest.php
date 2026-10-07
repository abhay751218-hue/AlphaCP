<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\LicenseSigner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LicenseServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['acp.license_secret' => 'test-secret']);
    }

    private function root(): User
    {
        $role = Role::query()->where('name', 'root')->firstOrFail();

        return User::query()->create([
            'username'      => 'licroot',
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
        $this->asRoot($this->root())->get('/license-server')->assertOk()->assertSee('License Server');
    }

    public function test_issue_produces_verifiable_key(): void
    {
        $this->asRoot($this->root())
            ->post('/license-server', ['server_id' => 'srv-1', 'plan' => 'pro', 'days' => 30])
            ->assertRedirect('/license-server');

        $key = session('new_key');
        $this->assertNotEmpty($key);

        $payload = LicenseSigner::verify($key, 'test-secret');
        $this->assertNotNull($payload);
        $this->assertSame('srv-1', $payload['sub']);
        $this->assertSame('pro', $payload['plan']);
        $this->assertDatabaseHas('license_keys', ['server_id' => 'srv-1', 'revoked' => false]);
    }

    public function test_verify_endpoint_valid(): void
    {
        $key = LicenseSigner::issue(['sub' => 's', 'exp' => time() + 100], 'test-secret');

        $this->post('/license-server/verify', ['key' => $key])
            ->assertOk()
            ->assertJson(['valid' => true]);
    }

    public function test_verify_rejects_tampered(): void
    {
        $key = LicenseSigner::issue(['sub' => 's', 'exp' => time() + 100], 'test-secret');

        $this->post('/license-server/verify', ['key' => $key . 'x'])
            ->assertOk()
            ->assertJson(['valid' => false]);
    }

    public function test_revoke_invalidates(): void
    {
        $u = $this->root();
        $this->asRoot($u)
            ->post('/license-server', ['server_id' => 'srv-2', 'plan' => 'starter', 'days' => 5]);

        $key = session('new_key');

        $this->asRoot($u)->delete('/license-server/1')->assertRedirect('/license-server');

        $this->post('/license-server/verify', ['key' => $key])
            ->assertOk()
            ->assertJson(['valid' => false, 'revoked' => true]);
    }
}
