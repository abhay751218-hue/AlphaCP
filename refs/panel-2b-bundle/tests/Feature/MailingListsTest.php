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

class MailingListsTest extends TestCase
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

    public function test_customer_can_add_mailing_list(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/mailing-lists')
            ->assertOk()
            ->assertSee('Mailing Lists')
            ->assertSee('Subscriber addresses');

        $this->asPanelUser($customer)->post('/mailing-lists', [
            'localpart' => 'news',
            'domain' => 'shop.example.com',
            'owner' => 'alice@example.net',
            'members' => "bob@example.net\nteam@example.org\nbob@example.net",
        ])->assertRedirect(route('mailing-lists.index'));

        $row = $account->fresh()->mailingLists()->first();
        $this->assertNotNull($row);
        $this->assertSame('news', $row->localpart);
        $this->assertSame('alice@example.net', $row->owner);
        $this->assertSame(['bob@example.net', 'team@example.org'], $row->members);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'mail.list')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('alice@example.net', $payload['lists'][0]['owner']);
        $this->assertSame(['bob@example.net', 'team@example.org'], $payload['lists'][0]['members']);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_owner_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/mailing-lists', [
            'localpart' => 'news',
            'domain' => 'shop.example.com',
            'owner' => '|/bin/sh',
            'members' => 'bob@example.net',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->mailingLists()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.list')->first());
    }

    public function test_pipe_subscriber_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/mailing-lists', [
            'localpart' => 'news',
            'domain' => 'shop.example.com',
            'owner' => 'alice@example.net',
            'members' => '|/bin/sh',
        ])->assertSessionHasErrors('members');
        $this->assertSame(0, $account->fresh()->mailingLists()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.list')->first());
    }

    public function test_customer_can_update_subscribers(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $row = $account->mailingLists()->create([
            'localpart' => 'news',
            'domain' => 'shop.example.com',
            'owner' => 'alice@example.net',
            'members' => ['old@example.net'],
        ]);

        $this->asPanelUser($customer)->patch('/mailing-lists/' . $row->id, [
            'owner' => 'owner@example.net',
            'members' => "z@example.net\na@example.org\nz@example.net",
        ])->assertRedirect(route('mailing-lists.index'));

        $fresh = $row->fresh();
        $this->assertSame('owner@example.net', $fresh->owner);
        $this->assertSame(['a@example.org', 'z@example.net'], $fresh->members);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'mail.list')->latest('id')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame(['a@example.org', 'z@example.net'], $payload['lists'][0]['members']);
    }

    public function test_foreign_domain_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/mailing-lists', [
            'localpart' => 'news',
            'domain' => 'evil.example.net',
            'owner' => 'alice@example.net',
            'members' => 'bob@example.net',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->mailingLists()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.list')->first());
    }

    public function test_customer_dashboard_has_mailing_lists_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Mailing Lists')
            ->assertDontSee('Create Account');
    }

    public function test_mail_role_can_open_mailing_lists(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/mailing-lists')
            ->assertOk()
            ->assertSee('Mailing Lists');
    }

    public function test_root_whm_hides_mailing_lists_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Mailing Lists<');
    }
}
