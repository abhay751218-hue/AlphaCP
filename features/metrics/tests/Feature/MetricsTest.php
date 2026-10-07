<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\Metrics;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MetricsTest extends TestCase
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

    private function writeLog(): string
    {
        $tmp  = tempnam(sys_get_temp_dir(), 'acplog');
        $body = implode("\n", [
            '1.2.3.4 - - [07/Oct/2026:10:00:00 +0000] "GET /index.html HTTP/1.1" 200 1000 "-" "t"',
            '1.2.3.4 - - [07/Oct/2026:10:00:01 +0000] "GET /about.html HTTP/1.1" 200 500 "-" "t"',
            '5.6.7.8 - - [07/Oct/2026:10:00:02 +0000] "GET /index.html HTTP/1.1" 404 100 "-" "t"',
        ]) . "\n";
        file_put_contents($tmp, $body);

        return $tmp;
    }

    public function test_metrics_parser_aggregates(): void
    {
        $tmp   = $this->writeLog();
        $stats = Metrics::parse($tmp);

        $this->assertSame(3, $stats['requests']);
        $this->assertSame(1600, $stats['bytes']);
        $this->assertSame(2, $stats['visitors']);
        $this->assertSame(1, $stats['errors']);
        $this->assertSame(2, $stats['top']['/index.html']);
    }

    public function test_customer_sees_metrics_page(): void
    {
        [$customer] = $this->customerWithAccount();
        config(['acp.access_log_pattern' => $this->writeLog()]);

        $this->asPanelUser($customer)
            ->get('/metrics')
            ->assertOk()
            ->assertSee('Metrics')
            ->assertSee('1.6 KB')      // bandwidth human(1600)
            ->assertSee('/index.html'); // top page
    }

    public function test_missing_log_shows_zeros(): void
    {
        [$customer] = $this->customerWithAccount();
        config(['acp.access_log_pattern' => '/nonexistent/nope.log']);

        $this->asPanelUser($customer)
            ->get('/metrics')
            ->assertOk()
            ->assertSee('Metrics');
    }
}
