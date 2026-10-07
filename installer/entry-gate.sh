#!/usr/bin/env bash
# ============================================================================
# AlphaCP — ENTRY SEPARATION (cPanel parity) installer  v1.0
# Server Manager (owner/reseller) entry: 8090/2087 · Account Panel entry: 2083/2096.
# Base LoginController untouched — routes ka import alias EntryLoginController
# par hota hai (jo port+role gate karta hai). 2083 enable na ho to single-entry
# mode (sab roles 8090 par). Idempotent + backup.
# ============================================================================
set -euo pipefail
PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"
echo "=================================================="
echo " AlphaCP Entry Separation installer  v1.0"
echo "=================================================="

echo "== Step 1: EntryLoginController =="
mkdir -p "$PANEL/app/Http/Controllers/Auth"
if [[ -f "$PANEL/app/Http/Controllers/Auth/EntryLoginController.php" && ! -f "$PANEL/app/Http/Controllers/Auth/EntryLoginController.php.bak" ]]; then
  cp "$PANEL/app/Http/Controllers/Auth/EntryLoginController.php" "$PANEL/app/Http/Controllers/Auth/EntryLoginController.php.bak"
fi
cat > "$PANEL/app/Http/Controllers/Auth/EntryLoginController.php" <<'ACP_FILE_EOF'
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
        $path = (string) (config('acp.ports_file') ?: '/usr/local/alphacp/var/ports.json');

        $ports = [8090];

        if (is_file($path)) {
            $cfg = json_decode((string) @file_get_contents($path), true);

            if (is_array($cfg) && is_array($cfg['ssl'] ?? null)) {
                $ports = array_map('intval', $cfg['ssl']);
            }
        }

        return array_values(array_unique($ports));
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/Auth/EntryLoginController.php"

echo "== Step 2: routes import alias (idempotent) =="
if ! grep -q "ACP-ENTRY-GATE" "$PANEL/routes/web.php"; then
  cp "$PANEL/routes/web.php" "$PANEL/routes/web.php.bak.entry"
  sed -i 's#^use App\\Http\\Controllers\\Auth\\LoginController;#use App\\Http\\Controllers\\Auth\\EntryLoginController as LoginController; // ACP-ENTRY-GATE#' "$PANEL/routes/web.php"
  if grep -q "ACP-ENTRY-GATE" "$PANEL/routes/web.php"; then
    echo "[OK] login routes ab EntryLoginController par"
  else
    echo "[FAIL] routes import match nahi hua — manual dekho"
    exit 1
  fi
else
  echo "[OK] alias pehle se laga hai"
fi

echo "== Step 3: caches clear =="
cd "$PANEL"
php artisan route:clear || true
php artisan config:clear || true
php artisan view:clear || true

echo "=================================================="
echo " ==> ENTRY SEPARATION v1.0 APPLIED"
echo "     8090/2087 = Server Manager · 2083/2096 = Account Panel"
echo "     (2083 enable nahi hai to single-entry mode chalta rahega)"
echo "=================================================="
alphacp-sync || true
