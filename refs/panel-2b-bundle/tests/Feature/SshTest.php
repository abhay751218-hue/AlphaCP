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

class SshTest extends TestCase
{
    use RefreshDatabase;

    private const PUB = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl laptop';

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
        return [$customer, $account, $pkg];
    }

    public function test_customer_can_import_public_key(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/ssh')
            ->assertOk()
            ->assertSee('SSH Access')
            ->assertSee('authorized_keys');

        $this->asPanelUser($customer)->post('/ssh', [
            'pubkey' => self::PUB,
        ])->assertRedirect(route('ssh.index'));

        $row = $account->fresh()->meta['ssh']['keys'][0];
        $this->assertSame('ssh-ed25519', $row['type']);
        $this->assertSame('laptop', $row['comment']);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'ssh.set')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('ssh-ed25519', $payload['keys'][0]['type']);
        $this->assertArrayNotHasKey('private', $payload['keys'][0]);
    }

    public function test_private_key_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/ssh', [
            'pubkey' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n",
        ])->assertRedirect();
        $this->assertSame([], $account->fresh()->meta['ssh']['keys'] ?? []);
        $this->assertNull(DB::table('tasks')->where('type', 'ssh.set')->first());
    }

    public function test_bash_without_hasshell_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/ssh/shell', [
            'shell' => 'bash',
        ])->assertRedirect();
        $this->assertSame('nologin', $account->fresh()->meta['ssh']['shell'] ?? 'nologin');
        $this->assertNull(DB::table('tasks')->where('type', 'ssh.set')->first());
    }

    public function test_customer_dashboard_has_ssh_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('SSH Access')
            ->assertDontSee('Create Account');
    }

    public function test_mail_cannot_open_ssh(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/ssh')->assertForbidden();
    }

    public function test_root_whm_hides_ssh_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>SSH Access<');
    }
}
