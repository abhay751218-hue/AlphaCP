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

class HandlersTest extends TestCase
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

    public function test_customer_can_add_handler(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/handlers')
            ->assertOk()
            ->assertSee('Apache Handlers')
            ->assertSee('AddHandler');

        $this->asPanelUser($customer)->post('/handlers', [
            'handler' => 'cgi-script',
            'ext' => 'cgi',
        ])->assertRedirect(route('handlers.index'));

        $rows = $account->fresh()->meta['handlers'];
        $this->assertSame('cgi', $rows[0]['ext']);
        $this->assertSame('cgi-script', $rows[0]['handler']);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'handlers.set')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('cgi-script', $payload['mappings'][0]['handler']);
    }

    public function test_php_handler_and_extension_are_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/handlers', [
            'handler' => 'php-script',
            'ext' => 'html',
        ])->assertRedirect();
        $this->asPanelUser($customer)->post('/handlers', [
            'handler' => 'cgi-script',
            'ext' => 'php',
        ])->assertRedirect();
        $this->assertSame([], $account->fresh()->meta['handlers'] ?? []);
        $this->assertNull(DB::table('tasks')->where('type', 'handlers.set')->first());
    }

    public function test_customer_dashboard_has_handlers_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Apache Handlers')
            ->assertDontSee('Create Account');
    }

    public function test_mail_cannot_open_handlers(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/handlers')->assertForbidden();
    }

    public function test_root_whm_hides_handlers_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Apache Handlers<');
    }
}
