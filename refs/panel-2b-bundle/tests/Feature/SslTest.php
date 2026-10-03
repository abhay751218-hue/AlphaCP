<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\DomainProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SslTest extends TestCase
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
        DomainProvisioner::seedMain($account);
        return [$customer, $account];
    }

    public function test_customer_sees_ssl_status_and_can_issue_letsencrypt(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/ssl')
            ->assertOk()
            ->assertSee('shop.example.com')
            ->assertSee('Run AutoSSL')
            ->assertSee("Let's Encrypt")
            ->assertSee('self-signed');

        $domain = Domain::query()->where('account_id', $account->id)->firstOrFail();
        $this->asPanelUser($customer)->post('/ssl/' . $domain->id, ['mode' => 'letsencrypt'])
            ->assertRedirect(route('ssl.index'));
        $this->assertSame('pending', $domain->fresh()->ssl_status);
        $this->assertSame('letsencrypt', $domain->fresh()->ssl_issuer);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'ssl.issue')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('shop.example.com', $payload['domain']);
        $this->assertSame('letsencrypt', $payload['mode']);
        $this->assertSame('c@example.com', $payload['email']);
    }

    public function test_customer_can_issue_self_signed_fallback(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $domain = Domain::query()->where('account_id', $account->id)->firstOrFail();
        $this->asPanelUser($customer)->post('/ssl/' . $domain->id, ['mode' => 'selfsigned'])
            ->assertRedirect(route('ssl.index'));
        $this->assertSame('selfsigned', $domain->fresh()->ssl_issuer);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'ssl.issue')->first();
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('selfsigned', $payload['mode']);
        $this->assertArrayNotHasKey('email', $payload);
    }

    public function test_run_autossl_queues_included_domains(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/ssl/autossl')->assertRedirect(route('ssl.index'));
        $this->assertSame(1, DB::table('tasks')->where('account_id', $account->id)->where('type', 'ssl.issue')->count());
        $this->assertSame('letsencrypt', Domain::query()->where('account_id', $account->id)->value('ssl_issuer'));
    }

    public function test_autossl_exclude_skips_domain(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $domain = Domain::query()->where('account_id', $account->id)->firstOrFail();
        $this->asPanelUser($customer)->post('/ssl/' . $domain->id . '/autossl')->assertRedirect(route('ssl.index'));
        $this->assertFalse((bool) $domain->fresh()->ssl_autossl);
        $this->asPanelUser($customer)->post('/ssl/autossl')->assertRedirect(route('ssl.index'));
        $this->assertSame(0, DB::table('tasks')->where('account_id', $account->id)->where('type', 'ssl.issue')->count());
    }

    public function test_customer_dashboard_has_ssl_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('SSL/TLS')
            ->assertDontSee('Create Account');
    }

    public function test_mail_cannot_open_ssl(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/ssl')->assertForbidden();
    }

    public function test_root_whm_dashboard_hides_ssl_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('SSL/TLS Status');
    }
}
