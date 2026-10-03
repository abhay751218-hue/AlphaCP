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

class PhpIniTest extends TestCase
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

    public function test_customer_can_edit_php_ini(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/php/ini')
            ->assertOk()
            ->assertSee('MultiPHP INI Editor')
            ->assertSee('memory_limit');

        $this->asPanelUser($customer)->post('/php/ini', [
            'memory_limit' => '256M',
            'display_errors' => 'On',
            'auto_prepend_file' => '/tmp/evil.php',
        ])->assertRedirect(route('php.ini'));

        $this->assertSame('256M', $account->fresh()->meta['php_ini']['memory_limit']);
        $this->assertArrayNotHasKey('auto_prepend_file', $account->fresh()->meta['php_ini']);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'php.setIni')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('256M', $payload['directives']['memory_limit']);
        $this->assertArrayNotHasKey('auto_prepend_file', $payload['directives']);
    }

    public function test_customer_dashboard_has_ini_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('MultiPHP INI Editor')
            ->assertDontSee('Create Account');
    }

    public function test_mail_cannot_open_php_ini(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/php/ini')->assertForbidden();
    }

    public function test_root_whm_hides_ini_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('MultiPHP INI Editor');
    }
}
