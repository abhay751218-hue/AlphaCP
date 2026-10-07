<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * cPanel parity: root/reseller 2083 par login nahi kar sakte, customer
 * 8090/2087 par nahi — jab tak owner ne 2083 enable kiya hai. Single-entry
 * mode (sirf 8090) me sab roles wahi login hote hain (pehle jaisa).
 */
class EntryGateTest extends TestCase
{
    use RefreshDatabase;

    private const PASS = 'CorrectHorse1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config(['acp.ports_file' => tempnam(sys_get_temp_dir(), 'ports')]);
    }

    private function ports(array $ssl): void
    {
        file_put_contents(
            (string) config('acp.ports_file'),
            json_encode(['ssl' => $ssl, 'http' => [], 'cpanel' => true, 'custom' => []]),
        );
    }

    private function makeUser(string $username, string $role): User
    {
        return User::query()->create([
            'username'      => $username,
            'password_hash' => Hash::make(self::PASS),
            'role_id'       => Role::query()->where('name', $role)->firstOrFail()->id,
            'status'        => 'active',
        ]);
    }

    public function test_manager_and_customer_entries_are_separated_when_2083_enabled(): void
    {
        $this->ports([8090, 2087, 2083, 2096]);
        $root = $this->makeUser('rootman', 'root');
        $res  = $this->makeUser('resone', 'reseller');
        $cust = $this->makeUser('custone', 'user');
        $mail = $this->makeUser('mailone', 'mail');

        $login = function (int $port, string $user) {
            // Har attempt fresh (guest) state me — pehla successful login
            // session hold karke baaki POSTs ko guest-redirect na karwa de.
            auth('web')->logout();
            $this->flushSession();

            return $this->post(
                "https://panel.test:{$port}/login",
                ['username' => $user, 'password' => self::PASS],
            );
        };

        // Customer entry (2083/2096): customers haan, managers nahi.
        $login(2083, $cust->username)->assertRedirect(route('dashboard'));
        $login(2096, $mail->username)->assertRedirect(route('dashboard'));
        $login(2083, $root->username)->assertSessionHasErrors('username');
        $login(2083, $res->username)->assertSessionHasErrors('username');

        // Manager entry (8090/2087): managers haan, customers nahi.
        $login(8090, $root->username)->assertRedirect(route('dashboard'));
        $login(2087, $res->username)->assertRedirect(route('dashboard'));
        $login(8090, $cust->username)->assertSessionHasErrors('username');
        $login(2087, $mail->username)->assertSessionHasErrors('username');
    }

    public function test_single_entry_mode_allows_every_role_on_8090(): void
    {
        $this->ports([8090]); // 2083 enabled nahi → gate band
        $root = $this->makeUser('rootman', 'root');
        $cust = $this->makeUser('custone', 'user');

        $this->post('https://panel.test:8090/login', ['username' => $root->username, 'password' => self::PASS])
            ->assertRedirect(route('dashboard'));
        auth('web')->logout();
        $this->flushSession();

        $this->post('https://panel.test:8090/login', ['username' => $cust->username, 'password' => self::PASS])
            ->assertRedirect(route('dashboard'));
    }

    public function test_wrong_password_gets_generic_error_not_entry_hint(): void
    {
        $this->ports([8090, 2083]);
        $this->makeUser('custone', 'user');

        $response = $this->from('https://panel.test:8090/')
            ->post('https://panel.test:8090/login', ['username' => 'custone', 'password' => 'WrongPass123']);

        $response->assertSessionHasErrors('username');
        $response->assertRedirect('https://panel.test:8090/');

        $error = session('errors')->first('username');
        $this->assertStringNotContainsString('2083', (string) $error, 'galat password par entry-hint leak nahi hona chahiye');
    }
}
