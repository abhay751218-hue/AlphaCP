<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * cPanel-jaisi ENTRY SEPARATION — v2 (truth-based, fail-open).
 *
 *   * Server Manager (root / reseller)  : 8090 (brand) + 2087/2086 (WHM compat)
 *   * Account Panel  (user / mail)      : 2083 (cPanel compat) + 2096 (webmail)
 *
 * Asli cPanel me root 2083 par login HI nahi ho sakta aur customer 2087 par
 * nahi — yahan wahi rule hai.
 *
 * v1 me ek design galti thi jis se panel LOCK ho jata tha:
 *   gate "ports.json" (owner ki /ports screen se save hui ichha) par bharosa
 *   karta tha, nginx par kya ACTUALLY listen ho raha hai us par nahi. Owner ne
 *   2083 enable kiya par nginx apply-step kabhi chala hi nahi (2083 par koi
 *   listener bana hi nahi) -> customer/user role ka har login 8090 par
 *   "Account Panel login 2083 par hota hai" se reject, aur 2083 kholne par
 *   connection refused. Isi tarah reverse-proxy (Cloudflare/tunnel, X-Forwarded
 *   -Port: 443) par getPort() 443 deta tha -> customer entry "unknown" -> phir
 *   bhi denial. Panel ke apne AuthTest bhi isi se fail hote the.
 *
 * v2 ke niyam (har ek ka matlab: kabhi koi user lockout NAHI hota):
 *   1. Truth file = /usr/local/alphacp/etc/entry-ports.json — ise ROOT ka
 *      `acp-entry-ports` script nginx/ss se banata hai, isliye isme sirf wahi
 *      ports hote hain jo sach me listen kar rahe hain. File nahi hai/mil nahi
 *      rahi = single-entry mode = gate OFF (pehle jaisa sab ek URL par).
 *   2. Denial sirf tab hota hai jab DUSRI entry sach me live ho.
 *   3. Port ya role pehchana na ja sake (proxy, 443, custom role) -> allow.
 *   4. Galat password/unknown user -> gate chup rehta hai, parent ka generic
 *      "Username or password is incorrect." hi dikhta hai (role enumeration
 *      port se nahi ho sakti).
 *   5. Koi bhi Throwable -> report + allow. Gate kabhi login todta nahi.
 *
 * Base LoginController untouched hai; routes ka import alias badalta hai:
 *   use App\Http\Controllers\Auth\EntryLoginController as LoginController;
 *
 * @acp-config acp.entry_ports_file (optional override of self::TRUTH_FILE)
 */
final class EntryLoginController extends LoginController
{
    /** WHM/Server Manager side ke candidate ports (8090 = AlphaCP brand port). */
    public const MANAGER_PORTS  = [8090, 2087, 2086];

    /** cPanel/Webmail (Account Panel) side ke candidate ports. */
    public const CUSTOMER_PORTS = [2083, 2096, 2082, 2095];

    public const MANAGER_ROLES  = ['root', 'reseller'];
    public const CUSTOMER_ROLES = ['user', 'mail'];

    /** Root likhta hai (0644), panel user padhta hai — open_basedir me allowed. */
    public const TRUTH_FILE = '/usr/local/alphacp/etc/entry-ports.json';

    public function login(Request $request): RedirectResponse
    {
        $denial = null;

        try {
            $denial = $this->entryDenial($request);
        } catch (\Throwable $e) {
            report($e);          // rule 5: gate kabhi login ko todta nahi
            $denial = null;
        }

        if ($denial !== null) {
            Audit::log('auth.entry_denied', 'warning', null, null, [
                'username' => self::asString($request->input('username')),
                'port'     => self::requestPort($request),
                'reason'   => $denial,
            ]);

            return back()->withErrors(['username' => $denial])->onlyInput('username');
        }

        return parent::login($request);
    }

    /**
     * Sirf VALID credentials par entry check hota hai — warna port se role
     * enumerate ho jata. Har "pata nahi" case me null (allow) milta hai.
     */
    private function entryDenial(Request $request): ?string
    {
        $live = self::liveEntries();

        if ($live === null) {
            return null;                       // single-entry mode, koi gate nahi
        }

        $username = self::asString($request->input('username'));
        $password = self::asString($request->input('password'));

        if ($username === '' || $password === '') {
            return null;                       // validation parent karega
        }

        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            return null;                       // unknown user -> generic error
        }

        $hash = is_string($user->password_hash) ? $user->password_hash : '';

        if ($hash === '' || ! Hash::check($password, $hash)) {
            return null;                       // galat password -> generic error
        }

        return self::decide(
            strtolower((string) ($user->role?->name ?? '')),
            self::requestPort($request),
            $live,
            self::hostLabel($request),
        );
    }

    /**
     * PURE decision — na DB, na file, na request. Isi wajah se ye panel ke
     * feature test me bhi chalta hai aur server ke `login-fix --selftest` me bhi.
     *
     * @param array{manager: list<int>, customer: list<int>}|null $live
     */
    public static function decide(string $role, ?int $port, ?array $live, string $host = '<host>'): ?string
    {
        if ($live === null || $port === null) {
            return null;                       // rule 3: confirm nahi -> allow
        }

        $manager  = in_array($role, self::MANAGER_ROLES, true);
        $customer = in_array($role, self::CUSTOMER_ROLES, true);

        if (! $manager && ! $customer) {
            return null;                       // unknown/custom role -> allow
        }

        $onManager  = in_array($port, $live['manager'], true);
        $onCustomer = in_array($port, $live['customer'], true);

        if (! $onManager && ! $onCustomer) {
            return null;                       // proxy / 443 / anjaan entry -> allow
        }

        // rule 2: dusri entry sach me live ho tabhi bhejo, warna lockout.
        if ($onCustomer && $manager && $live['manager'] !== []) {
            return 'Server Manager (owner/reseller) login '
                 . self::portLinks($live['manager'], $host) . ' par hota hai.';
        }

        if ($onManager && $customer && $live['customer'] !== []) {
            return 'Account Panel (customer/webmail) login '
                 . self::portLinks($live['customer'], $host) . ' par hota hai.';
        }

        return null;
    }

    /**
     * Truth file padho. Na mile / galat ho / koi maany prakar ka port na ho
     * -> null, matlab gate OFF (single-entry mode). Kabhi exception nahi.
     *
     * @return array{manager: list<int>, customer: list<int>}|null
     */
    public static function liveEntries(?string $path = null): ?array
    {
        if ($path === null) {
            $path = (string) config('acp.entry_ports_file');
            if ($path === '') {
                // ACP_HOME se bano — dev/sim box par /usr/local/alphacp hota hi nahi.
                $home = rtrim((string) config('acp.home', '/usr/local/alphacp'), '/');
                $path = ($home !== '' ? $home : '/usr/local/alphacp') . '/etc/entry-ports.json';
            }
        }
        $cfg  = self::readJson($path);

        if ($cfg === null) {
            return null;
        }

        $manager  = self::intList($cfg['manager'] ?? null, self::MANAGER_PORTS);
        $customer = self::intList($cfg['customer'] ?? null, self::CUSTOMER_PORTS);

        if ($manager === [] && $customer === []) {
            return null;                       // koi entry live hi nahi -> gate OFF
        }

        return ['manager' => $manager, 'customer' => $customer];
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $path): ?array
    {
        if ($path === '') {
            return null;
        }

        try {
            // open_basedir ke bahar ka path ErrorException deta hai -> catch.
            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }
            $raw = @file_get_contents($path);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $cfg = json_decode($raw, true);

        return is_array($cfg) ? $cfg : null;
    }

    /**
     * Truth file ke ports ko allowlist se kaato — koi bhi galat/bharosemand
     * file se panel anjaane port par redirect nahi karega.
     *
     * @param list<int> $allowed
     * @return list<int>
     */
    private static function intList(mixed $raw, array $allowed): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $p) {
            $n = is_numeric($p) ? (int) $p : 0;
            if (in_array($n, $allowed, true)) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    private static function requestPort(Request $request): ?int
    {
        try {
            $port = (int) $request->getPort();
        } catch (\Throwable) {
            return null;
        }

        return ($port > 0 && $port <= 65535) ? $port : null;
    }

    private static function hostLabel(Request $request): string
    {
        try {
            $host = (string) $request->getHost();
        } catch (\Throwable) {
            $host = '';
        }

        $host = trim($host);

        return ($host !== '' && ! str_contains($host, "\n")) ? $host : '<host>';
    }

    /** @param list<int> $ports */
    private static function portLinks(array $ports, string $host): string
    {
        $links = array_map(
            static fn (int $p): string => 'https://' . $host . ':' . $p,
            $ports,
        );

        return implode(' ya ', $links);
    }

    /** array/object/null input se PHP notice bachao (validation parent karta hai). */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
