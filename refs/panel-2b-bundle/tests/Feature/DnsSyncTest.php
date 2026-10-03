<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DnsSync;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DnsSyncTest extends TestCase
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

    public function test_root_can_queue_dns_sync(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dns-sync')
            ->assertOk()
            ->assertSee('DNS sync queue')
            ->assertSee('sync.json');

        $this->asPanelUser($root)->post('/dns-sync', [
            'domain' => 'example.com',
        ])->assertRedirect(route('dns-sync.index'));

        $row = DnsSync::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('example.com', $row->domain);
        $task = DB::table('tasks')->where('type', 'dns.sync')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/dns-sync', [
            'domain' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, DnsSync::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.sync')->first());
    }

    public function test_path_escape_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/dns-sync', [
            'domain' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, DnsSync::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.sync')->first());
    }

    public function test_root_dashboard_has_dns_sync_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Synchronize DNS Records')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_dns_sync(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Synchronize DNS Records')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_dns_sync(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/dns-sync')->assertForbidden();
        $this->asPanelUser($mail)->get('/dns-sync')->assertForbidden();
    }
}
