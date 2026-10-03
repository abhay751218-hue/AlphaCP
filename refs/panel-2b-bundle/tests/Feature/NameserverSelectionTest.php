<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NameserverSelection;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NameserverSelectionTest extends TestCase
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

    public function test_root_can_set_nameserver_selection(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/nameserver-selection')
            ->assertOk()
            ->assertSee('Nameserver selection')
            ->assertSee('nameserver.json');

        $this->asPanelUser($root)->post('/nameserver-selection', [
            'software' => 'bind',
            'ns1' => 'ns1.example.com',
            'ns2' => 'ns2.example.com',
        ])->assertRedirect(route('nameserver-selection.index'));

        $row = NameserverSelection::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('bind', $row->software);
        $this->assertSame('ns1.example.com', $row->ns1);
        $this->assertSame('ns2.example.com', $row->ns2);
        $task = DB::table('tasks')->where('type', 'dns.nameserver')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_ns1_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/nameserver-selection', [
            'software' => 'bind',
            'ns1' => '|/bin/sh',
            'ns2' => 'ns2.example.com',
        ])->assertRedirect();
        $this->assertSame(0, NameserverSelection::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.nameserver')->first());
    }

    public function test_path_escape_ns2_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/nameserver-selection', [
            'software' => 'bind',
            'ns1' => 'ns1.example.com',
            'ns2' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, NameserverSelection::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.nameserver')->first());
    }

    public function test_root_dashboard_has_nameserver_selection_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Nameserver Selection')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_nameserver_selection(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Nameserver Selection')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_nameserver_selection(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/nameserver-selection')->assertForbidden();
        $this->asPanelUser($mail)->get('/nameserver-selection')->assertForbidden();
    }
}
