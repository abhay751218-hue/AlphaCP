<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DnsClusterNode;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DnsClusterTest extends TestCase
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
            'username'      => 'dnsroot',
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
        $this->asRoot($this->root())->get('/dns-cluster')->assertOk()->assertSee('DNS Cluster');
    }

    public function test_add_node(): void
    {
        $this->asRoot($this->root())
            ->post('/dns-cluster', ['hostname' => 'ns2.example.com', 'ip' => '203.0.113.10', 'role' => 'ns'])
            ->assertRedirect('/dns-cluster');

        $this->assertDatabaseHas('dns_cluster_nodes', ['hostname' => 'ns2.example.com', 'role' => 'ns']);
    }

    public function test_sync_marks_synced(): void
    {
        DnsClusterNode::query()->create(['hostname' => 'ns1.x.com', 'ip' => '192.0.2.1', 'role' => 'dns']);

        $this->asRoot($this->root())->post('/dns-cluster/sync')->assertRedirect('/dns-cluster');

        $this->assertDatabaseHas('dns_cluster_nodes', ['hostname' => 'ns1.x.com', 'status' => 'synced']);
    }

    public function test_remove_node(): void
    {
        $n = DnsClusterNode::query()->create(['hostname' => 'ns9.x.com', 'ip' => '192.0.2.9', 'role' => 'dns']);

        $this->asRoot($this->root())->delete('/dns-cluster/' . $n->id)->assertRedirect('/dns-cluster');
        $this->assertDatabaseCount('dns_cluster_nodes', 0);
    }
}
