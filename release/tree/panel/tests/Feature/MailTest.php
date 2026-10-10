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

class MailTest extends TestCase
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

    public function test_customer_can_create_mailbox(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/email')
            ->assertOk()
            ->assertSee('Email Accounts')
            ->assertSee('Maildir');

        $this->asPanelUser($customer)->post('/email', [
            'localpart' => 'bob',
            'domain' => 'shop.example.com',
            'password' => 'CorrectHorse1',
            'quota_mb' => 512,
        ])->assertRedirect(route('email.index'));

        $box = $account->fresh()->mailboxes()->first();
        $this->assertNotNull($box);
        $this->assertSame('bob', $box->localpart);
        $this->assertSame('shop.example.com', $box->domain);
        $this->assertStringStartsWith('$2y$', $box->password_hash);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'mail.set')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('bob', $payload['mailboxes'][0]['local']);
        $this->assertArrayNotHasKey('password', $payload['mailboxes'][0]);
        $this->assertStringStartsWith('$2y$', $payload['mailboxes'][0]['hash']);
    }

    public function test_hostile_localpart_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/email', [
            'localpart' => '../root',
            'domain' => 'shop.example.com',
            'password' => 'CorrectHorse1',
            'quota_mb' => 10,
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->mailboxes()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.set')->first());
    }

    public function test_foreign_domain_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/email', [
            'localpart' => 'bob',
            'domain' => 'evil.example.net',
            'password' => 'CorrectHorse1',
            'quota_mb' => 10,
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->mailboxes()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.set')->first());
    }

    public function test_customer_dashboard_has_email_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Email Accounts')
            ->assertDontSee('Create Account');
    }

    public function test_mail_role_can_open_email_accounts(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/email')
            ->assertOk()
            ->assertSee('Email Accounts');
    }

    public function test_root_whm_hides_email_accounts_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Email Accounts<');
    }
}
