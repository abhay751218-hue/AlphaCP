<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GlobalEmailRoute;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GlobalEmailRoutingTest extends TestCase
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

    public function test_root_can_set_global_email_routing(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/global-email-routing')
            ->assertOk()
            ->assertSee('Global email routing')
            ->assertSee('global-routing.json');

        $this->asPanelUser($root)->post('/global-email-routing', [
            'domain' => 'example.com',
            'mode' => 'local',
        ])->assertRedirect(route('global-email-routing.index'));

        $row = GlobalEmailRoute::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('example.com', $row->domain);
        $this->assertSame('local', $row->mode);
        $task = DB::table('tasks')->where('type', 'mail.globalrouting')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/global-email-routing', [
            'domain' => '|/bin/sh',
            'mode' => 'local',
        ])->assertRedirect();
        $this->assertSame(0, GlobalEmailRoute::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.globalrouting')->first());
    }

    public function test_path_escape_mode_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/global-email-routing', [
            'domain' => 'example.com',
            'mode' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, GlobalEmailRoute::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.globalrouting')->first());
    }

    public function test_root_dashboard_has_global_email_routing_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Email Routing Configuration')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_global_email_routing(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Email Routing Configuration')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_global_email_routing(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/global-email-routing')->assertForbidden();
        $this->asPanelUser($mail)->get('/global-email-routing')->assertForbidden();
    }
}
