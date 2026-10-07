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
