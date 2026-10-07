<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmailDiskUsageTest extends TestCase
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

    public function test_customer_can_open_email_disk_usage(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/email-disk')
            ->assertOk()
            ->assertSee('Email Disk Usage')
            ->assertSee('~/mail');
    }

    public function test_path_escape_falls_back_to_mail_root(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/email-disk?path=../etc')
            ->assertOk()
            ->assertSee('Email Disk Usage');
    }

    public function test_pipe_path_falls_back_to_mail_root(): void
    {
        [$customer] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/email-disk?path=' . urlencode('|/bin/sh'))
            ->assertOk()
            ->assertSee('Email Disk Usage');
    }

    public function test_customer_dashboard_has_email_disk_usage_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Email Disk Usage')
            ->assertDontSee('Create Account');
    }

    public function test_mail_role_can_open_email_disk_usage(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/email-disk')
            ->assertOk()
            ->assertSee('Email Disk Usage');
    }

    public function test_root_whm_hides_email_disk_usage_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Email Disk Usage<');
    }
}
