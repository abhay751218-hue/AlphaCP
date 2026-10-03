<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\Files;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FilesTest extends TestCase
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

    public function test_customer_can_mkdir_in_file_manager(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/files')
            ->assertOk()
            ->assertSee('File Manager')
            ->assertSee('public_html');

        $this->asPanelUser($customer)->post('/files/mkdir', [
            'dir' => 'public_html',
            'name' => 'docs',
        ])->assertRedirect();

        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'files.set')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('mkdir', $payload['op']);
        $this->assertSame('public_html/docs', $payload['path']);
    }

    public function test_path_escape_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/files/write', [
            'dir' => 'public_html/../etc',
            'name' => 'passwd',
            'content' => 'x',
        ])->assertRedirect();
        $this->assertNull(DB::table('tasks')->where('type', 'files.set')->where('account_id', $account->id)->first());
    }

    public function test_customer_dashboard_has_file_manager_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('File Manager')
            ->assertDontSee('Create Account');
    }

    public function test_null_byte_path_is_rejected(): void
    {
        $this->assertNull(Files::tryRel("public_html/a\0b"));
        $this->assertSame('public_html/ok.txt', Files::tryRel('public_html/ok.txt'));
        $this->assertNull(Files::tryRel('../etc/passwd'));
    }

    public function test_mail_cannot_open_file_manager(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/files')->assertForbidden();
    }

    public function test_root_whm_hides_file_manager_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>File Manager<');
    }
}
