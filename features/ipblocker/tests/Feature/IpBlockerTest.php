<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BlockedIp;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class IpBlockerTest extends TestCase
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
        $pkg      = Package::query()->where('name', 'default')->firstOrFail();
        $account  = Account::query()->create([
            'server_id'     => 1,
            'package_id'    => $pkg->id,
            'owner_user_id' => $customer->id,
            'username'      => 'custhost',
            'main_domain'   => 'shop.example.com',
            'contact_email' => 'c@example.com',
            'home_path'     => '/home/custhost',
            'php_version'   => '8.4',
            'status'        => 'active',
            'quota_mb'      => 1024,
        ]);

        return [$customer, $account, $pkg];
    }

    public function test_customer_can_view_ip_blocker(): void
    {
        [$customer] = $this->customerWithAccount();

        $this->asPanelUser($customer)
            ->get('/ip-blocker')
            ->assertOk()
            ->assertSee('IP Blocker');
    }

    public function test_customer_can_block_ip(): void
    {
        Process::fake();
        [$customer] = $this->customerWithAccount();

        $this->asPanelUser($customer)
            ->post('/ip-blocker', ['ip' => '203.0.113.7', 'note' => 'brute-force'])
            ->assertRedirect('/ip-blocker');

        $this->assertDatabaseHas('blocked_ips', ['ip' => '203.0.113.7']);

        Process::assertRan(function ($process): bool {
            return is_array($process->command)
                && in_array('ufw', $process->command, true)
                && in_array('deny', $process->command, true)
                && in_array('203.0.113.7', $process->command, true);
        });
    }

    public function test_invalid_ip_rejected(): void
    {
        Process::fake();
        [$customer] = $this->customerWithAccount();

        $this->asPanelUser($customer)
            ->from('/ip-blocker')
            ->post('/ip-blocker', ['ip' => 'not-an-ip'])
            ->assertRedirect('/ip-blocker')
            ->assertSessionHasErrors('ip');

        $this->assertDatabaseCount('blocked_ips', 0);
    }

    public function test_customer_can_unblock_ip(): void
    {
        Process::fake();
        [$customer, $account] = $this->customerWithAccount();
        $row = BlockedIp::query()->create(['account_id' => $account->id, 'ip' => '203.0.113.7', 'note' => '']);

        $this->asPanelUser($customer)
            ->delete('/ip-blocker/' . $row->id)
            ->assertRedirect('/ip-blocker');

        $this->assertDatabaseCount('blocked_ips', 0);
    }
}
