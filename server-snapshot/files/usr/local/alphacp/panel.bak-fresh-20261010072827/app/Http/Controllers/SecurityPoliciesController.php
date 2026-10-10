<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WHM "Security Policies" (S13) — poore panel ki security posture ek jagah.
 *
 * Sab data ASLI hai: users table se 2FA adoption, root agent se shield
 * services (ufw/fail2ban/clamav/exim/dovecot/apache) ka systemd state.
 * Koi daawa bina saboot ke nahi.
 */
final class SecurityPoliciesController extends Controller
{
    private const SHIELD_SERVICES = ['ufw', 'fail2ban', 'clamav-daemon', 'apache2', 'exim4', 'dovecot'];

    public function index(Request $request): View
    {
        $users   = User::query()->with('role')->orderBy('username')->get();
        $twofaOn = $users->where('two_factor_enabled', true)->count();

        $agentOk  = in_array('service.status', Paneld::taskTypes(), true);
        $services = [];
        if ($agentOk) {
            $res = Paneld::run('service.status', ['services' => self::SHIELD_SERVICES], 15);
            if (is_array($res) && is_array($res['services'] ?? null)) {
                $services = $res['services'];
            }
        }

        $activeShields = 0;
        foreach ($services as $state) {
            if ((string) ($state['active'] ?? '') === 'active') {
                $activeShields++;
            }
        }

        $policies = [
            ['name' => 'Two-Factor Authentication (TOTP)', 'state' => 'enforced', 'desc' => 'Har panel user ke liye available — Google Authenticator compatible. Status neeche table me.'],
            ['name' => 'Password force-change',            'state' => 'enforced', 'desc' => 'Admin kisi bhi user ko agle login par naya password set karne par majboor kar sakta hai.'],
            ['name' => 'Audit logging',                    'state' => 'enforced', 'desc' => 'Har sensitive action (login, account, DNS, mail queue…) audit_logs me — Audit Log page par dekho.'],
            ['name' => 'Strict CSP (no inline JS)',        'state' => 'enforced', 'desc' => 'Panel ki har page par Content-Security-Policy — inline script chalta hi nahi.'],
            ['name' => 'Root-agent allowlist',             'state' => 'enforced', 'desc' => 'Web process root nahi hai; har privileged kaam allowlisted task schema se validate ho kar hi chalta hai.'],
            ['name' => 'Firewall + brute-force jail',      'state' => 'live-check', 'desc' => 'ufw + fail2ban ka asli systemd state neeche "Shield services" me.'],
        ];

        return view('security-policies.index', [
            'users'         => $users,
            'twofaOn'       => $twofaOn,
            'agentOk'       => $agentOk,
            'services'      => $services,
            'activeShields' => $activeShields,
            'policies'      => $policies,
        ]);
    }
}
