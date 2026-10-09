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

class PrivacyTest extends TestCase
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

    public function test_customer_can_protect_folder(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/privacy')
            ->assertOk()
            ->assertSee('Directory Privacy')
            ->assertSee('AuthUserFile');

        $this->asPanelUser($customer)->post('/privacy', [
            'path' => 'public_html/secret',
            'realm' => 'Secret',
            'name' => 'bob',
            'password' => 'CorrectHorse1',
        ])->assertRedirect(route('privacy.index'));

        $rows = $account->fresh()->meta['privacy'];
        $this->assertSame('public_html/secret', $rows[0]['path']);
        $this->assertSame('bob', $rows[0]['users'][0]['name']);
        $this->assertStringStartsWith('$2y$', $rows[0]['users'][0]['hash']);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'privacy.set')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('bob', $payload['entries'][0]['users'][0]['name']);
        $this->assertArrayNotHasKey('password', $payload['entries'][0]['users'][0]);
    }

    public function test_path_escape_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/privacy', [
            'path' => '../etc',
            'name' => 'bob',
            'password' => 'CorrectHorse1',
        ])->assertRedirect();
        $this->assertSame([], $account->fresh()->meta['privacy'] ?? []);
        $this->assertNull(DB::table('tasks')->where('type', 'privacy.set')->first());
    }

    public function test_customer_dashboard_has_privacy_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Directory Privacy')
            ->assertDontSee('Create Account');
    }

    public function test_mail_cannot_open_privacy(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/privacy')->assertForbidden();
    }

    public function test_root_whm_hides_privacy_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Directory Privacy<');
    }
}
