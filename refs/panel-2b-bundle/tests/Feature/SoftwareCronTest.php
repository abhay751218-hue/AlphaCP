<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CronJob;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
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
