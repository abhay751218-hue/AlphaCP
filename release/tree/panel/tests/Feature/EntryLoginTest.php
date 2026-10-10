<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Auth\EntryLoginController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Entry separation (Server Manager 8090/2087 vs Account Panel 2083/2096) —
 * regression tests for the LOCKOUT bug fixed by installer/login-fix.sh v1.0.
 *
 * Purana behaviour (v1) gate `ports.json` (owner ki ichha) se chalta tha:
 * owner ne 2083 "enable" kiya, nginx ne kabhi 2083 par suna nahi, aur
 * customer/user role ka har login 8090 par reject ho gaya. Isi wajah se
 * tests/Feature/AuthTest.php bhi fail hone lagta tha.
 *
 * Naya behaviour (v2): gate sirf us truth file ko maanta hai jo nginx ke
 * ASLI listening ports se banti hai, aur har "pata nahi" case me fail-OPEN
 * hai — koi bhi user kabhi lock nahi hota.
 */
class EntryLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private const LEVELS = ['root' => 1, 'reseller' => 2, 'user' => 3, 'mail' => 4];

    private function makeUsers(): void
    {
        foreach (self::LEVELS as $name => $level) {
            $role = Role::query()->firstOrCreate(
                ['name' => $name],
                ['label' => ucfirst($name), 'level' => $level, 'is_system' => true],
            );

            User::query()->create([
                'username'              => $name . 'u',
                'email'                 => $name . '@example.test',
                'password_hash'         => Hash::make('CorrectHorse1'),
                'role_id'               => $role->id,
                'status'                => 'active',
                'force_password_change' => false,
                'two_factor_enabled'    => false,
            ]);
        }
    }

    /** Truth file likho/hatao. `null` = gate OFF (single-entry mode). */
    private function truthFile(?array $manager, ?array $customer): string
    {
        $path = sys_get_temp_dir() . '/acp-entry-ports-' . uniqid() . '.json';

        if ($manager !== null) {
            file_put_contents($path, json_encode(['manager' => $manager, 'customer' => $customer ?? []]));
        }

        config()->set('acp.entry_ports_file', $manager === null ? $path . '.missing' : $path);

        return $path;
    }

    /** @return array{status:int, loc:?string, err:string} */
    private function attempt(string $base, string $username, string $password = 'CorrectHorse1'): array
    {
        Auth::guard('web')->logout();
        $this->flushSession();

        $response = $this->post($base . '/login', ['username' => $username, 'password' => $password]);

        // ErrorBag / MessageBag / plain array — sirf VALUES chahiye, keys nahi
        // (Laravel testing session me errors kabhi-kabhi [':message' => [...]]
        // shape me aate hain, isliye Arr::flatten keys bhi utha leta tha).
        $errs    = [];
        $collect = static function ($value) use (&$collect, &$errs): void {
            if ($value instanceof \Illuminate\Support\ViewErrorBag) {
                foreach ($value->getBags() as $bag) {
                    $collect($bag);
                }
                return;
            }
            if ($value instanceof \Illuminate\Contracts\Support\MessageBag) {
                foreach ($value->getMessages() as $messages) {
                    $collect($messages);
                }
                return;
            }
            if (is_array($value)) {
                // testing session me MessageBag serialize hoke
                // ['format' => ':message', 'messages' => [...]] ban jata hai
                if (isset($value['messages']) && is_array($value['messages'])) {
                    $collect($value['messages']);
                    return;
                }
                foreach ($value as $item) {
                    $collect($item);
                }
                return;
            }
            if (is_string($value) && $value !== '') {
                $errs[] = $value;
            }
        };
        $collect(session('errors'));

        return [
            'status' => $response->status(),
            'loc'    => $response->headers->get('Location'),
            'err'    => implode('|', $errs),
        ];
    }

    private function assertLoggedIn(string $base, string $username, string $why): void
    {
        $a = $this->attempt($base, $username);
        $this->assertTrue(
            $a['status'] === 302 && str_contains((string) $a['loc'], 'dashboard'),
            "{$why} => status={$a['status']} loc={$a['loc']} err=[{$a['err']}]",
        );
    }

    private function assertDenied(string $base, string $username, string $needle, string $why): void
    {
        $a = $this->attempt($base, $username);
        $this->assertStringContainsString($needle, $a['err'], "{$why} => err=[{$a['err']}] loc={$a['loc']}");
        $this->assertGuest();
    }

    // ------------------------------------------------------------------ pure

    public function test_decide_truth_table(): void
    {
        $both = ['manager' => [8090, 2087], 'customer' => [2083, 2096]];
        $only = ['manager' => [8090], 'customer' => []];

        $this->assertNull(EntryLoginController::decide('root', 8090, $both));
        $this->assertNull(EntryLoginController::decide('root', 2087, $both));
        $this->assertNull(EntryLoginController::decide('reseller', 8090, $both));
        $this->assertNull(EntryLoginController::decide('user', 2083, $both));
        $this->assertNull(EntryLoginController::decide('mail', 2096, $both));

        $this->assertStringContainsString('8090', (string) EntryLoginController::decide('root', 2083, $both));
        $this->assertStringContainsString('8090', (string) EntryLoginController::decide('reseller', 2096, $both));
        $this->assertStringContainsString('2083', (string) EntryLoginController::decide('user', 8090, $both));

        // fail-open cases — kabhi lockout nahi
        $this->assertNull(EntryLoginController::decide('user', 443, $both), 'proxy/443 par fail-open');
        $this->assertNull(EntryLoginController::decide('admin', 8090, $both), 'unknown role par fail-open');
        $this->assertNull(EntryLoginController::decide('user', null, $both), 'port unknown -> fail-open');
        $this->assertNull(EntryLoginController::decide('user', 8090, null), 'truth file nahi -> gate off');

        // B1 regression: customer entry live hi nahi, to customer ko 8090 se mat roko
        $this->assertNull(EntryLoginController::decide('user', 8090, $only), 'sirf 8090 live -> customer allow');
        $this->assertNull(EntryLoginController::decide('mail', 8090, $only));
    }

    public function test_live_entries_rejects_unknown_ports(): void
    {
        $path = $this->truthFile([8090, 9999, 'x'], [2083, 1234]);
        $live = EntryLoginController::liveEntries($path);

        $this->assertSame(['manager' => [8090], 'customer' => [2083]], $live);
        @unlink($path);
    }

    public function test_live_entries_null_when_file_missing(): void
    {
        $this->assertNull(EntryLoginController::liveEntries('/nonexistent/acp-entry-ports.json'));
    }

    // ------------------------------------------------------- end to end

    public function test_no_truth_file_means_everyone_logs_in_on_8090(): void
    {
        $this->makeUsers();
        $this->truthFile(null, null);

        foreach (['rootu', 'reselleru', 'useru', 'mailu'] as $u) {
            $this->assertLoggedIn('https://panel.test:8090', $u, "gate off: {$u}");
        }
    }

    public function test_only_8090_live_means_no_customer_lockout(): void
    {
        $this->makeUsers();
        $path = $this->truthFile([8090], []);

        $this->assertLoggedIn('https://panel.test:8090', 'rootu', 'manager 8090');
        $this->assertLoggedIn('https://panel.test:8090', 'reselleru', 'reseller 8090');
        $this->assertLoggedIn('https://panel.test:8090', 'useru', 'customer 8090 (pehle LOCKOUT tha)');
        $this->assertLoggedIn('https://panel.test:8090', 'mailu', 'mail 8090 (pehle LOCKOUT tha)');
        @unlink($path);
    }

    public function test_real_separation_when_both_entries_are_live(): void
    {
        $this->makeUsers();
        $path = $this->truthFile([8090, 2087], [2083, 2096]);

        $this->assertLoggedIn('https://panel.test:8090', 'rootu', 'root@8090');
        $this->assertLoggedIn('https://panel.test:2087', 'reselleru', 'reseller@2087');
        $this->assertLoggedIn('https://panel.test:2083', 'useru', 'customer@2083');
        $this->assertLoggedIn('https://panel.test:2096', 'mailu', 'mail@2096');

        $this->assertDenied('https://panel.test:2083', 'rootu', '8090', 'root@customer-entry');
        $this->assertDenied('https://panel.test:2096', 'reselleru', '8090', 'reseller@webmail-entry');
        $this->assertDenied('https://panel.test:8090', 'useru', '2083', 'customer@manager-entry');
        @unlink($path);
    }

    public function test_wrong_password_still_gives_the_generic_error(): void
    {
        $this->makeUsers();
        $path = $this->truthFile([8090, 2087], [2083, 2096]);

        // Port se role enumerate na ho: galat password par generic hi error.
        $a = $this->attempt('https://panel.test:8090', 'useru', 'WrongPass123');
        $this->assertSame('Username or password is incorrect.', $a['err']);

        $b = $this->attempt('https://panel.test:2083', 'rootu', 'WrongPass123');
        $this->assertSame('Username or password is incorrect.', $b['err']);
        @unlink($path);
    }

    public function test_array_input_does_not_crash_the_gate(): void
    {
        $this->makeUsers();
        $path = $this->truthFile([8090], []);

        Auth::guard('web')->logout();
        $this->flushSession();
        $r = $this->post('https://panel.test:8090/login', ['username' => ['a'], 'password' => ['b']]);

        $this->assertNotSame(500, $r->status(), 'array input par gate crash nahi hona chahiye');
        @unlink($path);
    }

    public function test_authenticated_session_without_two_factor_flag_does_not_loop(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'root'], ['label' => 'Root', 'level' => 1, 'is_system' => true]);
        User::query()->create([
            'username' => 'rootu', 'email' => 'r@example.test', 'password_hash' => Hash::make('CorrectHorse1'),
            'role_id' => $role->id, 'status' => 'active', 'force_password_change' => false,
            'two_factor_enabled' => false,
        ]);
        $this->truthFile(null, null);

        $this->be(User::query()->where('username', 'rootu')->firstOrFail());
        $this->withSession([]);   // two_factor_passed gayab — pehle infinite loop banta tha

        $hops = [];
        $url  = '/dashboard';
        for ($i = 0; $i < 6; $i++) {
            $r   = $this->get($url);
            $loc = $r->headers->get('Location');
            $hops[] = $url . ' -> ' . $r->status() . ($loc ? ' ' . $loc : ' (render)');
            if ($r->status() !== 302 || ! $loc) {
                break;
            }
            $url = parse_url($loc, PHP_URL_PATH) ?: '/';
        }

        $this->assertSame(200, $r->status(), 'REDIRECT LOOP: ' . implode(' | ', $hops));
    }

    public function test_get_login_serves_the_login_page(): void
    {
        $this->truthFile(null, null);
        $this->get('/login')->assertOk()->assertSee('Panel Login');
        $this->get('/')->assertOk()->assertSee('Panel Login');
    }
}
