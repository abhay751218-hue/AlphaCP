<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ParkedDomain;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ParkDomainTest extends TestCase
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

    public function test_root_can_park_a_domain(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/park-domain')
            ->assertOk()
            ->assertSee('Parked domains')
            ->assertSee('parked.json');

        $this->asPanelUser($root)->post('/park-domain', [
            'domain' => 'alias.example.com',
            'target' => 'example.com',
        ])->assertRedirect(route('park-domain.index'));

        $row = ParkedDomain::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('alias.example.com', $row->domain);
        $this->assertSame('example.com', $row->target);
        $task = DB::table('tasks')->where('type', 'dns.park')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/park-domain', [
            'domain' => '|/bin/sh',
            'target' => 'example.com',
        ])->assertRedirect();
        $this->assertSame(0, ParkedDomain::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.park')->first());
    }

    public function test_path_escape_target_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/park-domain', [
            'domain' => 'alias.example.com',
            'target' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, ParkedDomain::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.park')->first());
    }

    public function test_root_dashboard_has_park_a_domain_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Park a Domain')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_park_a_domain(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Park a Domain')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_park_a_domain(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/park-domain')->assertForbidden();
        $this->asPanelUser($mail)->get('/park-domain')->assertForbidden();
    }
}
