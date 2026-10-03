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

class ErrorPagesTest extends TestCase
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

    public function test_customer_can_save_error_pages(): void
    {
        [$customer, $account] = $this->customerWithAccount();
        $this->asPanelUser($customer)->get('/errorpages')
            ->assertOk()
            ->assertSee('Error Pages')
            ->assertSee('404');

        $this->asPanelUser($customer)->post('/errorpages', [
            'pages' => [
                '404' => '<h1>Missing</h1>',
                '500' => '<?php echo 1; ?>',
            ],
        ])->assertRedirect(route('errorpages.index'));

        $this->assertSame('<h1>Missing</h1>', $account->fresh()->meta['error_pages']['404']);
        $this->assertArrayNotHasKey('500', $account->fresh()->meta['error_pages']);
        $task = DB::table('tasks')->where('account_id', $account->id)->where('type', 'errorpages.set')->first();
        $this->assertNotNull($task);
        $payload = json_decode((string) $task->payload, true);
        $this->assertSame('<h1>Missing</h1>', $payload['pages']['404']);
        $this->assertArrayNotHasKey('500', $payload['pages']);
    }

    public function test_customer_dashboard_has_error_pages_not_create_account(): void
    {
        $customer = $this->userWithRole('user');
        $this->asPanelUser($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Error Pages')
            ->assertDontSee('Create Account');
    }

    public function test_mail_cannot_open_error_pages(): void
    {
        $mail = $this->userWithRole('mail');
        $this->asPanelUser($mail)->get('/errorpages')->assertForbidden();
    }

    public function test_root_whm_hides_error_pages_tile(): void
    {
        $root = $this->userWithRole('root');
        $this->asPanelUser($root)->get('/dashboard')
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('>Error Pages<');
    }
}
