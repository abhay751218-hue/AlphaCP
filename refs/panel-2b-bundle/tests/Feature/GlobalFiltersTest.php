<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GlobalFiltersTest extends TestCase
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

    private function customerWithAccount(): array
    {
        $customer = $this->userWithRole('user');
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        $account = Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'owner_user_id' => $customer->id,
            'username' => 'custhost',
            'main_domain' => 'shop.example.com',
            'contact_email' => 'c@example.com',
            'home_path' => '/home/custhost',
            'php_version' => '8.4',
            'status' => 'active',
            'quota_mb' => 1024,
        ]);
        return [$customer, $account];
    }

    public function test_customer_can_add_global_filter(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/global-filters')
            ->assertOk()
            ->assertSee('Global Email Filters')
            ->assertSee('global-filters.json');

        $this->asPanelUser($customer)->post('/global-filters', [
            'domain' => 'shop.example.com',
            'field' => 'subject',
            'needle' => 'viagra',
            'action' => 'discard',
        ])->assertRedirect(route('global-filters.index'));

        $row = $account->fresh()->globalFilters()->first();
        $this->assertNotNull($row);
        $this->assertSame('shop.example.com', $row->domain);
        $this->assertSame('viagra', $row->needle);
        $this->assertSame('discard', $row->action);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'mail.gfilter')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('viagra', $payload['filters'][0]['needle']);
        $this->assertArrayNotHasKey('local', $payload['filters'][0]);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_needle_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/global-filters', [
            'domain' => 'shop.example.com',
            'field' => 'subject',
            'needle' => '|/bin/sh',
            'action' => 'discard',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->globalFilters()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.gfilter')->first());
    }

    public function test_foreign_domain_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/global-filters', [
            'domain' => 'evil.example.net',
            'field' => 'subject',
            'needle' => 'spam',
            'action' => 'discard',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->globalFilters()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.gfilter')->first());
    }

    public function test_customer_dashboard_has_global_filters_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Global Email Filters')
            ->assertDontSee('Create Account');
    }

    public function test_mail_role_can_open_global_filters(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/global-filters')
            ->assertOk()
            ->assertSee('Global Email Filters');
    }

    public function test_root_whm_hides_global_filters_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Global Email Filters<');
    }
}
