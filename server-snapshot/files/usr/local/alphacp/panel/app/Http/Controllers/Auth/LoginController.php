<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Panel login (admin + reseller + client share one URL — role decides the UI).
 *
 * Protections: rate limit, per-user failed-login lockout (cPHulk-lite),
 * session regeneration, audit of every attempt, optional 2FA step.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $ip       = (string) $request->ip();
        $username = trim($data['username']);

        $user = User::query()->where('username', $username)->first();

        // Failures are counted per username+IP pair.
        $fail = function (string $reason, ?User $user = null) use ($username, $ip): RedirectResponse {
            LoginAttempt::query()->create([
                'username' => $username, 'ip' => $ip, 'success' => false, 'reason' => $reason,
            ]);
            Audit::log('auth.login_failed', 'warning', 'user', $user?->id, ['username' => $username, 'reason' => $reason]);

            if ($user) {
                $user->increment('failed_logins');
                $max = (int) config('acp.security.max_login_attempts', 5);
                if ($user->failed_logins + 1 >= $max) {
                    $user->forceFill([
                        'locked_until'  => now()->addMinutes((int) config('acp.security.lockout_minutes', 15)),
                        'failed_logins' => 0,
                    ])->save();
                    Audit::log('auth.account_locked', 'critical', 'user', $user->id, ['minutes' => config('acp.security.lockout_minutes')]);
                }
            }

            return back()->withErrors(['username' => 'Username or password is incorrect.'])->onlyInput('username');
        };

        if (! $user) {
            return $fail('unknown_user');
        }
        if ($user->isLocked()) {
            Audit::log('auth.login_blocked_locked', 'warning', 'user', $user->id);
            return back()->withErrors(['username' => 'Account is locked for a short time. Try again later.'])->onlyInput('username');
        }
        if (! $user->isActive()) {
            return $fail('status_' . $user->status, $user);
        }
        if (! Hash::check($data['password'], $user->password_hash)) {
            return $fail('bad_password', $user);
        }

        // ---- success -------------------------------------------------------
        Auth::login($user, remember: false);              // no "remember me" (panel policy)
        $request->session()->regenerate();
        $request->session()->put('two_factor_passed', ! $user->two_factor_enabled);

        $user->forceFill([
            'failed_logins' => 0,
            'locked_until'  => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        LoginAttempt::query()->create(['username' => $username, 'ip' => $ip, 'success' => true]);
        Audit::log('auth.login', 'info', 'user', $user->id, ['ip' => $ip]);

        if ($user->two_factor_enabled) {
            return redirect()->route('twofactor.challenge');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::log('auth.logout', 'info', 'user', $request->user()?->id);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
