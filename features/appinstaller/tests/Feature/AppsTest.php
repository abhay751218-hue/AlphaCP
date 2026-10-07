<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AppsTest extends TestCase
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

    private function customerWithTempAccount(): array
    {
        $customer = $this->userWithRole('user');
        $pkg      = Package::query()->where('name', 'default')->firstOrFail();
        $tmp      = sys_get_temp_dir() . '/acphome_' . uniqid();
        File::makeDirectory($tmp, 0755, true, true);
        $account  = Account::query()->create([
            'server_id'     => 1,
            'package_id'    => $pkg->id,
            'owner_user_id' => $customer->id,
            'username'      => 'custhost',
            'main_domain'   => 'shop.example.com',
            'contact_email' => 'c@example.com',
            'home_path'     => $tmp,
            'php_version'   => '8.4',
            'status'        => 'active',
            'quota_mb'      => 1024,
        ]);

        return [$customer, $account, $pkg, $tmp];
    }

    public function test_view_shows_catalog(): void
    {
        [$customer] = $this->customerWithTempAccount();

        $this->asPanelUser($customer)
            ->get('/apps')
            ->assertOk()
            ->assertSee('WordPress');
    }

    public function test_install_wordpress(): void
    {
        Process::fake();
        [$customer, $account, $pkg, $tmp] = $this->customerWithTempAccount();

        $this->asPanelUser($customer)
            ->post('/apps', ['app' => 'wordpress'])
            ->assertRedirect('/apps');

        Process::assertRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'mysql'));
        Process::assertRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'curl'));

        $this->assertFileExists($tmp . '/public_html/wp-config.php');
        $this->assertStringContainsString("DB_NAME', 'custhost_wp'", File::get($tmp . '/public_html/wp-config.php'));

        File::deleteDirectory($tmp);
    }
}
