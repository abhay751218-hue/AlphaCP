#!/usr/bin/env bash
# AlphaCP — open_basedir 500 PERMANENT fix  v1.0
# Root cause: EntryLoginController var/ports.json is_file() karta tha jo
# open_basedir se bahar hai → ErrorException → har request 500.
# Fix: controllers ab etc/ports.json (allowed) padhte hain, try/catch ke saath;
# PortsController etc/ me likhta hai. var/ copy CLI/agent ke liye bani rahti hai.
set -uo pipefail
PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"
ETC="${ACP_ETC:-/usr/local/alphacp/etc}"
echo "=================================================="
echo " AlphaCP open_basedir 500 Fix  v1.0"
echo "=================================================="
mkdir -p "$PANEL/app/Http/Controllers/Auth" "$PANEL/app/Http/Controllers" "$ETC"
cat > "$PANEL/app/Http/Controllers/Auth/EntryLoginController.php" <<'ACP_PHP_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * cPanel-jaisi ENTRY SEPARATION:
 *   * Server Manager (owner/reseller) entry : 8090 / 2087
 *   * Account Panel (customer/mail) entry   : 2083 / 2096
 *
 * Asli cPanel me root 2083 par login HI nahi ho sakta, aur customer WHM port
 * par nahi — yahan wahi rule hai. Separation sirf tab active hota hai jab
 * owner ne 2083 (customer entry) actually enable kiya hai (ports.json);
 * warna single-entry mode: sab roles 8090 par (pehle jaisa).
 *
 * Galat entry par denial sirf VALID credentials par hota hai — galat password
 * par wahi generic error milta hai, taaki port se role-enumeration na ho.
 * Base LoginController untouched; routes ka import alias installer badalta hai.
 */
final class EntryLoginController extends LoginController
{
    /** @var list<int> */
    private const CUSTOMER_PORTS = [2083, 2096];

    public function login(Request $request): RedirectResponse
    {
        $denial = $this->entryDenial($request);

        if ($denial !== null) {
            return back()->withErrors(['username' => $denial])->onlyInput('username');
        }

        return parent::login($request);
    }

    private function entryDenial(Request $request): ?string
    {
        $ssl = $this->listeningSslPorts();

        // Customer entry port enabled hi nahi → single-entry mode, koi gate nahi.
        if (! in_array(2083, $ssl, true)) {
            return null;
        }

        $username = trim((string) $request->input('username'));
        $password = (string) $request->input('password');

        $user = User::query()->where('username', $username)->first();

        if ($user === null || ! Hash::check($password, $user->password_hash)) {
            return null; // parent generic "incorrect" dega — enumeration nahi.
        }

        $role = (string) ($user->role?->name ?? '');
        $port = (int) $request->getPort();

        $customerRole = in_array($role, ['user', 'mail'], true);
        $managerRole  = in_array($role, ['root', 'reseller'], true);
        $onCustomer   = in_array($port, self::CUSTOMER_PORTS, true);

        if ($onCustomer && $managerRole) {
            return 'Server Manager (owner/reseller) login 8090 ya 2087 par hota hai.';
        }

        if (! $onCustomer && $customerRole) {
            return 'Account Panel login 2083 par hota hai — https://<host>:2083 kholein.';
        }

        return null;
    }

    /** @return list<int> */
    private function listeningSslPorts(): array
    {
        // open_basedir-safe: etc/ copy web-readable hai; var/ legacy (CLI-only).
        // Koi bhi path fail ho (basedir/missing) -> default [8090], kabhi crash nahi.
        $cfgPath = (string) (config('acp.ports_file') ?: '');

        $candidates = array_filter([
            $cfgPath,
            '/usr/local/alphacp/etc/ports.json',
            '/usr/local/alphacp/var/ports.json',
        ]);

        $ports = [8090];

        foreach ($candidates as $path) {
            try {
                if (! is_file($path)) {
                    continue;
                }
                $cfg = json_decode((string) @file_get_contents($path), true);
            } catch (\Throwable) {
                continue; // open_basedir ErrorException etc.
            }

            if (is_array($cfg) && is_array($cfg['ssl'] ?? null)) {
                $ports = array_map('intval', $cfg['ssl']);
                break;
            }
        }

        return array_values(array_unique($ports));
    }
}
ACP_PHP_EOF
echo "  + $PANEL/app/Http/Controllers/Auth/EntryLoginController.php"
cat > "$PANEL/app/Http/Controllers/PortsController.php" <<'ACP_PHP_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PortConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Owner Ports Config — owner yahan se panel ports choose karta hai.
 * Save par DB + var/ports.json likhta hai; apply-step (installer/agent) ise nginx par lagata hai.
 * 8090 hamesha primary (brand) port hai.
 */
final class PortsController extends Controller
{
    public const PRIMARY = 8090;
    private const CPANEL_SSL  = [2083, 2087, 2096];
    private const CPANEL_HTTP = [2082, 2086, 2095];

    public function index(): View
    {
        return view('ports.index', ['cfg' => $this->current()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cpanel' => 'nullable|boolean',
            'custom' => 'nullable|string',
        ]);

        $cpanel = $request->boolean('cpanel');

        $custom = [];
        foreach (preg_split('/[\s,]+/', (string) ($data['custom'] ?? '')) ?: [] as $p) {
            $n = (int) $p;
            if ($n > 1023 && $n < 65536 && $n !== self::PRIMARY) {
                $custom[] = $n;
            }
        }
        $custom = array_values(array_unique($custom));

        $ssl  = array_values(array_unique(array_merge([self::PRIMARY], $cpanel ? self::CPANEL_SSL : [], $custom)));
        $http = $cpanel ? self::CPANEL_HTTP : [];

        $cfg = ['ssl' => $ssl, 'http' => $http, 'cpanel' => $cpanel, 'custom' => $custom];

        // single row upsert
        $rec = PortConfig::query()->first();
        if ($rec) {
            $rec->update(['data' => $cfg]);
        } else {
            PortConfig::query()->create(['data' => $cfg]);
        }

        try {
            File::put($this->portsFile(), json_encode($cfg, JSON_PRETTY_PRINT));
        } catch (\Throwable) {
            // DB source-of-truth hai; file copy agent/apply-step bhi sync karta hai.
        }

        return redirect('/ports')->with('success', 'Ports save ho gaye. Ab apply-step chalayen (ya agent auto-apply).');
    }

    /** @return array{ssl:list<int>,http:list<int>,cpanel:bool,custom:list<int>} */
    private function current(): array
    {
        $rec = PortConfig::query()->first();

        return $rec?->data ?? ['ssl' => [self::PRIMARY], 'http' => [], 'cpanel' => false, 'custom' => []];
    }

    private function portsFile(): string
    {
        // etc/ open_basedir-allowed hai (web user read/write kar sakta hai).
        return (string) (config('acp.ports_file') ?: '/usr/local/alphacp/etc/ports.json');
    }
}
ACP_PHP_EOF
echo "  + $PANEL/app/Http/Controllers/PortsController.php"
# ---- ports.json: etc/ copy (web-readable) ensure ----
if [ ! -f "$ETC/ports.json" ] && [ -f /usr/local/alphacp/var/ports.json ]; then
  cp /usr/local/alphacp/var/ports.json "$ETC/ports.json" && echo "  + var/ports.json -> etc/ports.json copied"
fi
chown root:alphacp "$ETC/ports.json" 2>/dev/null && chmod 0640 "$ETC/ports.json" 2>/dev/null || true
# ---- fpm restart (opcache flush) ----
systemctl restart php8.4-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || service php8.4-fpm restart 2>/dev/null || true
# ---- verify ----
echo "-- http status:"
curl -sk -o /dev/null -w '   /       => %{http_code}\n' "https://127.0.0.1:8090/" 2>/dev/null || true
curl -sk -o /dev/null -w '   /login  => %{http_code}\n' "https://127.0.0.1:8090/login" 2>/dev/null || true
echo "=================================================="
echo " ==> OPEN_BASEDIR FIX v1.0 APPLIED"
echo "=================================================="
alphacp-sync || true
