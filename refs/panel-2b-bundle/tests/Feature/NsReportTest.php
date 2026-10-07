<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NsRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NsReportTest extends TestCase
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

    public function test_root_can_add_ns_report_row(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/ns-report')
            ->assertOk()
            ->assertSee('Nameserver records')
            ->assertSee('ns-report.json');

        $this->asPanelUser($root)->post('/ns-report', [
            'domain' => 'example.com',
            'nameserver' => 'ns1.example.com',
        ])->assertRedirect(route('ns-report.index'));

        $row = NsRecord::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('example.com', $row->domain);
        $this->assertSame('ns1.example.com', $row->nameserver);
        $task = DB::table('tasks')->where('type', 'dns.nsreport')->first();
        $this->assertNotNull($task);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_domain_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/ns-report', [
            'domain' => '|/bin/sh',
            'nameserver' => 'ns1.example.com',
        ])->assertRedirect();
        $this->assertSame(0, NsRecord::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.nsreport')->first());
    }

    public function test_path_escape_nameserver_is_rejected(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->post('/ns-report', [
            'domain' => 'example.com',
            'nameserver' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, NsRecord::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'dns.nsreport')->first());
    }

    public function test_root_dashboard_has_ns_report_and_create_account(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Nameserver Record Report')
            ->assertSee('Create Account');
    }

    public function test_customer_dashboard_hides_ns_report(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Track DNS')
            ->assertDontSee('Nameserver Record Report')
            ->assertDontSee('Create Account');
    }

    public function test_customer_and_mail_cannot_open_ns_report(): void
    {
        $customer = $this->userWithRole('user');
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($customer)->get('/ns-report')->assertForbidden();
        $this->asPanelUser($mail)->get('/ns-report')->assertForbidden();
    }
}
