<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BackupRestore;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FileRestorationTest extends TestCase
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

    public function test_customer_can_queue_file_restoration(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/file-restoration')
            ->assertOk()
            ->assertSee('File Restoration')
            ->assertSee('restore.json');

        $this->asPanelUser($customer)->post('/file-restoration', [
            'path' => 'public_html/index.php',
        ])->assertRedirect(route('file-restoration.index'));

        $row = BackupRestore::query()->where('account_id', $account->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('public_html/index.php', $row->path);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'backup.restore')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('public_html/index.php', $payload['paths'][0]['path']);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_pipe_path_is_rejected(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/file-restoration', [
            'path' => '|/bin/sh',
        ])->assertRedirect();
        $this->assertSame(0, BackupRestore::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.restore')->first());
    }

    public function test_path_escape_is_rejected(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/file-restoration', [
            'path' => '../etc',
        ])->assertRedirect();
        $this->assertSame(0, BackupRestore::query()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'backup.restore')->first());
    }

    public function test_customer_dashboard_has_file_restoration_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('File Restoration')
            ->assertDontSee('Create Account');
    }

    public function test_mail_role_cannot_open_file_restoration(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/file-restoration')->assertForbidden();
    }

    public function test_root_whm_hides_file_restoration_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>File Restoration<');
    }
}
