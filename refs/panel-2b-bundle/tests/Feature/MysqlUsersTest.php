<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MysqlUsersTest extends TestCase
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
        $account->mysqlDatabases()->create(['name' => 'shop']);

        return [$customer, $account];
    }

    public function test_customer_creates_a_user_with_a_grant_and_sees_the_password_once(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $database = $account->mysqlDatabases()->firstOrFail();

        $this->asPanelUser($customer)->get('/mysql-users')
            ->assertOk()
            ->assertSee('MySQL Users')
            ->assertSee('db.user.create')
            ->assertSee('custhost_shop');

        $response = $this->asPanelUser($customer)->post('/mysql-users', [
            'user'      => 'wp_admin',
            'host'      => 'localhost',
            'databases' => [$database->id],
        ])->assertRedirect(route('mysql-users.index'));

        $password = session('mysql_user_password');
        $this->assertIsString($password);
        $this->assertSame(20, strlen($password));
        $this->assertDoesNotMatchRegularExpression("/['\\\\|]/", $password);

        $user = MysqlUser::query()->where('account_id', $account->id)->firstOrFail();
        $this->assertSame('wp_admin', $user->name);
        $this->assertSame('localhost', $user->host);
        $this->assertTrue($user->databases()->whereKey($database->id)->exists());

        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'db.user.create')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('custhost', $payload['username']);
        $this->assertSame('wp_admin', $payload['user']);
        $this->assertSame('localhost', $payload['host']);
        $this->assertSame(['shop'], $payload['databases']);
        $this->assertSame($password, $payload['password']);
        $this->assertStringNotContainsString('|', (string) $task->payload);

        // the very next request must not leak the password again
        $this->asPanelUser($customer)->get('/mysql-users')
            ->assertOk()
            ->assertSee('custhost_wp_admin');
        $this->assertNull(session('mysql_user_password'));

        $this->asPanelUser($customer)->get('/mysql-users')
            ->assertOk()
            ->assertDontSee($password);
    }

    public function test_password_reset_queues_alter_user_and_shows_the_new_password_once(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $user = $account->mysqlUsers()->create(['name' => 'wp_admin', 'host' => 'localhost']);

        $this->asPanelUser($customer)->post('/mysql-users/' . $user->id . '/password')
            ->assertRedirect(route('mysql-users.index'));

        $password = session('mysql_user_password');
        $this->assertIsString($password);
        $this->assertSame(20, strlen($password));

        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'db.user.password')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('wp_admin', $payload['user']);
        $this->assertSame('localhost', $payload['host']);
        $this->assertSame($password, $payload['password']);

        // the redirect target shows it once …
        $this->asPanelUser($customer)->get('/mysql-users')
            ->assertOk()
            ->assertSee($password);
        // … and the request after that does not
        $this->asPanelUser($customer)->get('/mysql-users')
            ->assertOk()
            ->assertDontSee($password);
    }

    public function test_password_reset_for_a_foreign_user_is_forbidden(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $otherAccount = Account::query()->create([
            'server_id'     => 1,
            'package_id'    => $account->package_id,
            'username'      => 'otherhost',
            'main_domain'   => 'other.example.com',
            'contact_email' => 'o@example.com',
            'home_path'     => '/home/otherhost',
            'php_version'   => '8.4',
            'status'        => 'active',
            'quota_mb'      => 1024,
        ]);
        $foreign = $otherAccount->mysqlUsers()->create(['name' => 'foreign', 'host' => 'localhost']);

        $this->asPanelUser($customer)->post('/mysql-users/' . $foreign->id . '/password')->assertForbidden();
        $this->assertNull(DB::table('tasks')->where('type', 'db.user.password')->first());
    }

    public function test_pipe_user_name_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/mysql-users', [
            'user' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->mysqlUsers()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'db.user.create')->first());
    }

    public function test_host_is_allowlisted(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/mysql-users', [
            'user' => 'bad_host',
            'host' => 'evil.example.com; DROP',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->mysqlUsers()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'db.user.create')->first());

        $this->asPanelUser($customer)->post('/mysql-users', [
            'user' => 'remote_user',
            'host' => '%',
        ])->assertRedirect(route('mysql-users.index'));
        $this->assertSame('%', $account->fresh()->mysqlUsers()->firstOrFail()->host);
    }

    public function test_add_user_to_database_queues_a_real_grant(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $database = $account->mysqlDatabases()->firstOrFail();
        $user = $account->mysqlUsers()->create(['name' => 'wp_admin', 'host' => 'localhost']);

        $this->asPanelUser($customer)->post('/mysql-users/grant', [
            'mysql_user_id'     => $user->id,
            'mysql_database_id' => $database->id,
        ])->assertRedirect(route('mysql-users.index'));

        $this->assertTrue($user->fresh()->databases()->whereKey($database->id)->exists());
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'db.user.grant')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('wp_admin', $payload['user']);
        $this->assertSame('shop', $payload['database']);

        // a second identical grant is refused, not silently queued twice
        $before = DB::table('tasks')->where('type', 'db.user.grant')->count();
        $this->asPanelUser($customer)->post('/mysql-users/grant', [
            'mysql_user_id'     => $user->id,
            'mysql_database_id' => $database->id,
        ])->assertRedirect();
        $this->assertSame($before, DB::table('tasks')->where('type', 'db.user.grant')->count());
    }

    public function test_customer_cannot_grant_or_delete_another_accounts_user(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $database = $account->mysqlDatabases()->firstOrFail();
        $mine = $account->mysqlUsers()->create(['name' => 'mine', 'host' => 'localhost']);

        $otherAccount = Account::query()->create([
            'server_id'     => 1,
            'package_id'    => $account->package_id,
            'username'      => 'otherhost',
            'main_domain'   => 'other.example.com',
            'contact_email' => 'o@example.com',
            'home_path'     => '/home/otherhost',
            'php_version'   => '8.4',
            'status'        => 'active',
            'quota_mb'      => 1024,
        ]);
        $foreign = $otherAccount->mysqlUsers()->create(['name' => 'foreign', 'host' => 'localhost']);

        $this->asPanelUser($customer)->post('/mysql-users/grant', [
            'mysql_user_id'     => $foreign->id,
            'mysql_database_id' => $database->id,
        ])->assertNotFound();

        $this->asPanelUser($customer)->delete('/mysql-users/' . $foreign->id)->assertForbidden();
        $this->assertNotNull($foreign->fresh());

        $this->asPanelUser($customer)->delete('/mysql-users/' . $mine->id)->assertRedirect(route('mysql-users.index'));
        $this->assertNull($mine->fresh());
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'db.user.drop')->first();
        $this->assertNotNull($task);
        $this->assertSame('db.user.drop', json_decode((string) $task->payload, true)['_confirm']);
    }

    public function test_dropping_a_database_detaches_its_grants_and_queues_a_confirmed_drop(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $database = $account->mysqlDatabases()->firstOrFail();
        $user = $account->mysqlUsers()->create(['name' => 'wp_admin', 'host' => 'localhost']);
        $user->databases()->attach($database->id);

        $this->asPanelUser($customer)->delete('/mysql/' . $database->id)->assertRedirect(route('mysql.index'));

        $this->assertNull(MysqlDatabase::query()->find($database->id));
        $this->assertSame(0, $user->fresh()->databases()->count());
        $task = DB::table('tasks')->where('type', 'db.drop')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('shop', $payload['name']);
        $this->assertSame('db.drop', $payload['_confirm']);
    }

    public function test_customer_and_mail_cannot_open_the_users_page(): void
    {
        // Create fixture users before actingAs(); the reseller scope correctly
        // prevents a non-root actor from creating a root-role account.
        $whm = $this->userWithRole('root');
        $this->customerWithAccount();
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/mysql-users')->assertForbidden();

        $this->asPanelUser($whm)->get('/mysql-users')
            ->assertOk()
            ->assertSee('customer workspace');
    }
}
