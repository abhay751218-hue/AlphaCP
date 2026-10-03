<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DomainForward;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DomainForwardTest extends TestCase
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

    public function test_root_can_set_domain_forward(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/domain-forward')
            ->assertOk()
            ->assertSee('Domain forwarding')
            ->assertSee('forward.json');

        $this->asPanelUser($root)->post('/domain-forward', [
            'domain' => 'old.example.com',
            'url' => 'https://example.com',
            'code' => '301',
        ])->assertRedirect(route('domain-forward.index'));

        $row = DomainForward::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('old.example.com', $row->domain);
        $this->assertSame('https://example.com', $row->url);
        $this->assertSame(301, $row->code);
        $task = DB::table('tasks')->where('type', 'dns.forward')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/domain-forward', [
            'domain' => '|/bin/sh',
            'url' => 'https://example.com',
            'code' => '301',
        ])->assertRedirect();
        $this->assertSame(0, DomainForward::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.forward')->first());
    }

    public function test_path_escape_url_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/domain-forward', [
            'domain' => 'old.example.com',
            'url' => '../etc',
            'code' => '301',
        ])->assertRedirect();
        $this->assertSame(0, DomainForward::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.forward')->first());
    }

    public function test_root_dashboard_has_domain_forward_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Setup/Edit Domain Forwarding')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_domain_forward(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Setup/Edit Domain Forwarding')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_domain_forward(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/domain-forward')->assertForbidden();
        $this->asPanelUser($mail)->get('/domain-forward')->assertForbidden();
    }
}
