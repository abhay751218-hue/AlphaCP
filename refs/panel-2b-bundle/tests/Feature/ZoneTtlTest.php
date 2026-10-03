<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\ZoneTtl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ZoneTtlTest extends TestCase
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

    public function test_root_can_set_zone_ttl(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/zone-ttl')
            ->assertOk()
            ->assertSee('Zone TTL')
            ->assertSee('ttl.json');

        $this->asPanelUser($root)->post('/zone-ttl', [
            'domain' => 'example.com',
            'ttl' => '3600',
        ])->assertRedirect(route('zone-ttl.index'));

        $row = ZoneTtl::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('example.com', $row->domain);
        $this->assertSame(3600, $row->ttl);
        $task = DB::table('tasks')->where('type', 'dns.ttl')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/zone-ttl', [
            'domain' => '|/bin/sh',
            'ttl' => '3600',
        ])->assertRedirect();
        $this->assertSame(0, ZoneTtl::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.ttl')->first());
    }

    public function test_path_escape_ttl_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/zone-ttl', [
            'domain' => 'example.com',
            'ttl' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, ZoneTtl::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.ttl')->first());
    }

    public function test_root_dashboard_has_zone_ttl_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Set Zone TTL')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_zone_ttl(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Set Zone TTL')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_zone_ttl(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/zone-ttl')->assertForbidden();
        $this->asPanelUser($mail)->get('/zone-ttl')->assertForbidden();
    }
}
