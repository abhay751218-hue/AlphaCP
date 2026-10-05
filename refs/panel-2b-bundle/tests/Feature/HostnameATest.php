<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HostnameA;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HostnameATest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::query()->create([
            'username'      => 'u_' . $roleName,
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id'       => $role->id,
            'status'        => 'active',
        ]);
    }

    private function asPanelUser(User $user): static
    {
        return $this->withSession(['two_factor_passed' => true])->actingAs($user->fresh());
    }

    public function test_root_can_set_hostname_a(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/hostname-a')
            ->assertOk()
            ->assertSee('Hostname A')
            ->assertSee('hostname.json');

        $this->asPanelUser($root)->post('/hostname-a', [
            'hostname' => 'server.example.com',
            'ip' => '203.0.113.10',
        ])->assertRedirect(route('hostname-a.index'));

        $row = HostnameA::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('server.example.com', $row->hostname);
        $this->assertSame('203.0.113.10', $row->ip);
        $task = DB::table('tasks')->where('type', 'dns.hostname')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('server.example.com', $payload['hostname']);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_hostname_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/hostname-a', [
            'hostname' => '|/bin/sh',
            'ip' => '203.0.113.10',
        ])->assertRedirect();
        $this->assertSame(0, HostnameA::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.hostname')->first());
    }

    public function test_path_escape_ip_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/hostname-a', [
            'hostname' => 'server.example.com',
            'ip' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, HostnameA::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.hostname')->first());
    }

    public function test_root_dashboard_has_hostname_a_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Add an A Entry for Your Hostname')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_hostname_a(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Add an A Entry for Your Hostname')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_hostname_a(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/hostname-a')->assertForbidden();
        $this->asPanelUser($mail)->get('/hostname-a')->assertForbidden();
    }
}
