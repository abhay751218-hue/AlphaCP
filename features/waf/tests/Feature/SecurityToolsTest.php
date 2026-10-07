<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SecurityToolsTest extends TestCase
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

    public function test_view_shows_waf_status(): void
    {
        Process::fake(['*' => Process::result(exitCode: 0)]);
        [$customer] = $this->customerWithAccount();

        $this->asPanelUser($customer)
            ->get('/security-tools')
            ->assertOk()
            ->assertSee('Security Tools')
            ->assertSee('ENABLED');
    }

    public function test_toggle_enables_modsec_when_off(): void
    {
        Process::fake([
            'a2query *'  => Process::result(exitCode: 1),
            'a2enmod *'  => Process::result(),
            'systemctl *' => Process::result(),
        ]);
        [$customer] = $this->customerWithAccount();

        $this->asPanelUser($customer)
            ->post('/security-tools/modsec')
            ->assertRedirect('/security-tools');

        Process::assertRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'a2enmod'));
    }

    public function test_scan_runs_clamscan(): void
    {
        Process::fake([
            'clamscan *' => Process::result(output: 'Scanned 0 infected'),
        ]);
        [$customer] = $this->customerWithAccount();

        $this->asPanelUser($customer)
            ->post('/security-tools/scan')
            ->assertRedirect('/security-tools');

        Process::assertRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'clamscan'));
    }
}
