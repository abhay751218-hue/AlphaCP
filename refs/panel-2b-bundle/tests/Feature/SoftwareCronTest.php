<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CronJob;
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

class SoftwareCronTest extends TestCase
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

    public function test_customer_can_change_php_version(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/php')->assertOk()->assertSee('MultiPHP');
        $this->asPanelUser($customer)->post('/php', ['php_version' => '8.3'])->assertRedirect(route('php.index'));
        $this->assertSame('8.3', $account->fresh()->php_version);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'php.setVersion')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('8.3', $payload['php_version']);
    }

    private function domainFor(Account $account, string $fqdn = 'blog.shop.example.com'): Domain
    {
        DomainProvisioner::seedMain($account);

        return Domain::query()->create([
            'account_id' => $account->id,
            'type' => 'sub',
            'domain' => $fqdn,
            'document_root' => rtrim($account->home_path, '/') . '/public_html/blog',
            'php_version' => $account->php_version,
            'status' => 'active',
        ]);
    }

    public function test_customer_can_set_per_domain_php_version(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $sub = $this->domainFor($account);
        $this->asPanelUser($customer)->get('/php')->assertOk()->assertSee('Per-domain PHP version');

        $this->asPanelUser($customer)->post('/php/domain/' . $sub->id, ['php_version' => '8.3'])
            ->assertRedirect(route('php.index'));

        $this->assertSame('8.3', $sub->fresh()->php_version);
        $this->assertSame('8.4', $account->fresh()->php_version, 'account PHP untouched');
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'php.setVersion')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('blog.shop.example.com', $payload['domain']);
        $this->assertSame('8.3', $payload['php_version']);
    }

    public function test_per_domain_php_stays_inside_the_account(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->domainFor($account);

        $role = Role::query()->where('name', 'user')->firstOrFail();
        $other = User::query()->create([
            'username' => 'u_otherown',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        $acct2 = Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'owner_user_id' => $other->id,
            'username' => 'otherhost',
            'main_domain' => 'other.example.com',
            'contact_email' => 'o@example.com',
            'home_path' => '/home/otherhost',
            'status' => 'active',
            'quota_mb' => 100,
        ]);
        $stolen = Domain::query()->create([
            'account_id' => $acct2->id,
            'type' => 'sub',
            'domain' => 'stolen.other.example.com',
            'document_root' => '/home/otherhost/public_html/stolen',
            'status' => 'active',
        ]);

        $this->asPanelUser($customer)->post('/php/domain/' . $stolen->id, ['php_version' => '8.3'])
            ->assertForbidden();
        $this->assertSame(0, DB::table('tasks')->where('type', 'php.setVersion')->count());
    }

    public function test_per_domain_php_rejects_bad_version_and_redirect_type(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $sub = $this->domainFor($account);

        $this->asPanelUser($customer)->post('/php/domain/' . $sub->id, ['php_version' => '5.6'])
            ->assertSessionHasErrors('php_version');

        $redirect = Domain::query()->create([
            'account_id' => $account->id,
            'type' => 'redirect',
            'domain' => 'go.shop.example.com',
            'document_root' => rtrim($account->home_path, '/') . '/public_html',
            'redirect_url' => 'https://example.com/',
            'redirect_code' => 301,
            'status' => 'active',
        ]);
        $this->asPanelUser($customer)->post('/php/domain/' . $redirect->id, ['php_version' => '8.3'])
            ->assertSessionHasErrors('php_version');
    }

    public function test_customer_can_save_per_domain_ini(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $sub = $this->domainFor($account);
        $this->asPanelUser($customer)->get('/php/ini')->assertOk()->assertSee('Per-domain INI');

        $this->asPanelUser($customer)->post('/php/ini/domain/' . $sub->id, [
            'memory_limit' => '256M',
            'display_errors' => 'Off',
        ])->assertRedirect(route('php.ini'));

        $this->assertSame('256M', $sub->fresh()->php_ini['memory_limit']);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'php.setIni')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('blog.shop.example.com', $payload['domain']);
        $this->assertSame('8.4', $payload['php_version']);
        $this->assertSame('256M', $payload['directives']['memory_limit']);
    }

    public function test_per_domain_ini_stays_inside_the_account(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $sub = $this->domainFor($account);
        $role = Role::query()->where('name', 'user')->firstOrFail();
        $other = User::query()->create([
            'username' => 'u_thirdown',
            'password_hash' => Hash::make('CorrectHorse1'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        $acct2 = Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'owner_user_id' => $other->id,
            'username' => 'thirdhost',
            'main_domain' => 'third.example.com',
            'contact_email' => 't@example.com',
            'home_path' => '/home/thirdhost',
            'status' => 'active',
            'quota_mb' => 100,
        ]);
        $foreign = Domain::query()->create([
            'account_id' => $acct2->id,
            'type' => 'sub',
            'domain' => 'x.third.example.com',
            'document_root' => '/home/thirdhost/public_html/x',
            'status' => 'active',
        ]);

        $this->asPanelUser($customer)->post('/php/ini/domain/' . $foreign->id, ['memory_limit' => '256M'])
            ->assertForbidden();
        $this->assertNull($foreign->fresh()->php_ini);
        $this->assertSame(0, DB::table('tasks')->where('type', 'php.setIni')->count());
        $this->assertSame('8.4', $sub->fresh()->php_version);
    }

    public function test_customer_can_add_and_delete_cron(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/cron')->assertOk()->assertSee('Cron Jobs');
        $this->asPanelUser($customer)->post('/cron', [
            'minute' => '0', 'hour' => '2', 'day' => '*', 'month' => '*', 'weekday' => '*',
            'command' => '/home/custhost/bin/daily.sh',
        ])->assertRedirect(route('cron.index'));

        $job = CronJob::query()->where('account_id', $account->id)->first();
        $this->assertNotNull($job);
        $task = DB::table('tasks')->where('type', 'cron.set')->where('account_id', $account->id)->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('/home/custhost/bin/daily.sh', $payload['jobs'][0]['command']);

        $this->asPanelUser($customer)->delete('/cron/' . $job->id)->assertRedirect();
        $this->assertDatabaseMissing('cron_jobs', ['id' => $job->id]);
    }

    public function test_cron_rejects_newline_command(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/cron', [
            'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*',
            'command' => "echo hi\nrm -rf /",
        ])->assertSessionHasErrors('command');
    }

    public function test_whm_php_on_account_show_enqueues_task(): void
    {
        $root = $this->userWithRole('root');
        $pkg = Package::query()->where('name', 'default')->firstOrFail();
        $account = Account::query()->create([
            'server_id' => 1,
            'package_id' => $pkg->id,
            'username' => 'bobhost',
            'main_domain' => 'bob.example.com',
            'contact_email' => 'b@example.com',
            'home_path' => '/home/bobhost',
            'php_version' => '8.4',
            'status' => 'active',
            'quota_mb' => 512,
        ]);
        $this->asPanelUser($root)->post("/accounts/{$account->id}/php", [
            'php_version' => '8.2',
        ])->assertRedirect();
        $this->assertSame('8.2', $account->fresh()->php_version);
        $this->assertDatabaseHas('tasks', ['account_id' => $account->id, 'type' => 'php.setVersion']);
    }

    public function test_customer_cannot_use_whm_php_route(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post("/accounts/{$account->id}/php", [
            'php_version' => '8.3',
        ])->assertForbidden();
    }

    public function test_mail_cannot_open_php_or_cron(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/php')->assertForbidden();
        $this->asPanelUser($mail)->get('/cron')->assertForbidden();
    }
}
