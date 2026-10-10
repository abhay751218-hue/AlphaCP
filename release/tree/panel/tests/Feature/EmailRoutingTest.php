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

class EmailRoutingTest extends TestCase
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

    public function test_customer_can_set_email_routing(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/email-routing')
            ->assertOk()
            ->assertSee('Email Routing')
            ->assertSee('routing.json');

        $this->asPanelUser($customer)->post('/email-routing', [
            'domain' => 'shop.example.com',
            'mode' => 'local',
        ])->assertRedirect(route('email-routing.index'));

        $row = $account->fresh()->emailRoutes()->first();
        $this->assertNotNull($row);
        $this->assertSame('shop.example.com', $row->domain);
        $this->assertSame('local', $row->mode);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'mail.routing')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('local', $payload['routes'][0]['mode']);
        $this->assertStringNotContainsString('|', (string) $task->payload);
    }

    public function test_invalid_mode_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/email-routing', [
            'domain' => 'shop.example.com',
            'mode' => 'exec',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->emailRoutes()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.routing')->first());
    }

    public function test_foreign_domain_is_rejected(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->post('/email-routing', [
            'domain' => 'evil.example.net',
            'mode' => 'local',
        ])->assertRedirect();
        $this->assertSame(0, $account->fresh()->emailRoutes()->count());
        $this->assertNull(DB::table('tasks')->where('type', 'mail.routing')->first());
    }

    public function test_customer_dashboard_has_email_routing_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Email Routing')
            ->assertDontSee('Create Account');
    }

    public function test_mail_role_can_open_email_routing(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/email-routing')
            ->assertOk()
            ->assertSee('Email Routing');
    }

    public function test_root_whm_hides_email_routing_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Email Routing<');
    }
}
