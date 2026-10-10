<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DnsCleanup;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DnsCleanupTest extends TestCase
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

    public function test_root_can_queue_dns_cleanup(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dns-cleanup')
            ->assertOk()
            ->assertSee('DNS cleanup queue')
            ->assertSee('cleanup.json');

        $this->asPanelUser($root)->post('/dns-cleanup', [
            'domain' => 'stale.example.com',
        ])->assertRedirect(route('dns-cleanup.index'));

        $row = DnsCleanup::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('stale.example.com', $row->domain);
        $task = DB::table('tasks')->where('type', 'dns.cleanup')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/dns-cleanup', [
            'domain' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, DnsCleanup::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.cleanup')->first());
    }

    public function test_path_escape_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/dns-cleanup', [
            'domain' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, DnsCleanup::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.cleanup')->first());
    }

    public function test_root_dashboard_has_dns_cleanup_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Perform a DNS Cleanup')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_dns_cleanup(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Perform a DNS Cleanup')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_dns_cleanup(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/dns-cleanup')->assertForbidden();
        $this->asPanelUser($mail)->get('/dns-cleanup')->assertForbidden();
    }
}
