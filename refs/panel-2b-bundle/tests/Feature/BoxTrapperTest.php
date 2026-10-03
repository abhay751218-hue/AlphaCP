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

class BoxTrapperTest extends TestCase
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

    public function test_customer_can_set_boxtrapper(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/boxtrapper')
            ->assertOk()
            ->assertSee('BoxTrapper')
            ->assertSee('boxtrapper.json');

        $this->asPanelUser($customer)->post('/boxtrapper', [
            'enabled' => '1',
            'dest' => 'alice@example.net',
        ])->assertRedirect(route('boxtrapper.index'));

        $row = $account->fresh()->boxTrapperSetting;
        $this->assertNotNull($row);
        $this->assertTrue($row->enabled);
        $this->assertSame(['alice@example.net'], $row->allowlist);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'mail.boxtrapper')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertTrue($payload['enabled']);
        $this->assertSame('alice@example.net', $payload['allowlist'][0]);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_dest_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/boxtrapper', [
            'enabled' => '1',
            'dest' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertNull($account->fresh()->boxTrapperSetting);
        $this->assertNull(DB::table('tasks')->where('type', 'mail.boxtrapper')->first());
    }

    public function test_invalid_enabled_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/boxtrapper', [
            'enabled' => 'exec',
            'dest' => 'alice@example.net',
        ])->assertRedirect();
        $this->assertNull($account->fresh()->boxTrapperSetting);
        $this->assertNull(DB::table('tasks')->where('type', 'mail.boxtrapper')->first());
    }

    public function test_customer_dashboard_has_boxtrapper_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('BoxTrapper')
            ->assertDontSee('Create Account');
    }

    public function test_mail_role_can_open_boxtrapper(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/boxtrapper')
            ->assertOk()
            ->assertSee('BoxTrapper');
    }

    public function test_root_whm_hides_boxtrapper_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>BoxTrapper<');
    }
}
