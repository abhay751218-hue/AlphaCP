<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DnsTemplate;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ZoneTemplatesTest extends TestCase
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

    public function test_root_can_add_zone_template(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/zone-templates')
            ->assertOk()
            ->assertSee('Zone templates')
            ->assertSee('templates.json');

        $this->asPanelUser($root)->post('/zone-templates', [
            'name' => 'standard',
            'body' => '%domain%. IN A %ip%',
        ])->assertRedirect(route('zone-templates.index'));

        $row = DnsTemplate::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('standard', $row->name);
        $this->assertSame('%domain%. IN A %ip%', $row->body);
        $task = DB::table('tasks')->where('type', 'dns.templates')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_name_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/zone-templates', [
            'name' => '|/bin/sh',
            'body' => '%domain%. IN A %ip%',
        ])->assertRedirect();
        $this->assertSame(0, DnsTemplate::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.templates')->first());
    }

    public function test_path_escape_body_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/zone-templates', [
            'name' => 'standard',
            'body' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, DnsTemplate::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.templates')->first());
    }

    public function test_root_dashboard_has_zone_templates_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Edit Zone Templates')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_zone_templates(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Edit Zone Templates')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_zone_templates(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/zone-templates')->assertForbidden();
        $this->asPanelUser($mail)->get('/zone-templates')->assertForbidden();
    }
}
