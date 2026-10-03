<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Security page: 2FA setup, password change, active sessions (cPanel "Security" section). */
class SecurityController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Pending 2FA secret is kept in the SESSION (encrypted at rest by Laravel)
        // until the user proves they can read codes from it.
        $pending = $request->session()->get('2fa_pending');

        return view('security.index', [
            'user'      => $user,
            'pending'   => $pending,
            'otpauth'   => $pending ? Totp::provisioningUri($pending, $user->username) : null,
            'pretty'    => $pending ? Totp::prettySecret($pending) : null,
            'sessions'  => $this->deviceSessions($user->id),
        ]);
    }

    public function startTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->two_factor_enabled, 400, '2FA pehle se on hai.');

        $secret = Totp::generateSecret();
        $request->session()->put('2fa_pending', $secret);

        Audit::log('security.2fa_setup_started', 'info', 'user', $user->id);

        return redirect()->route('security.index')
            ->with('success', 'Authenticator app me secret add karo, phir 6-digit code daal kar confirm karo.');
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $user   = $request->user();
        $secret = $request->session()->get('2fa_pending');

        if (! $secret) {
            return redirect()->route('security.index')->withErrors(['code' => 'Pehle "Enable 2FA" dabao.']);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $step = Totp::verify($secret, $data['code']);

        if ($step === null) {
            return back()->withErrors(['code' => 'Code match nahi hua — app ka time theek hai? Dobara try karo.']);
        }

        $user->forceFill([
            'two_factor_secret'        => Crypt::encryptString($secret),
            'two_factor_enabled'       => true,
            'two_factor_confirmed_at'  => now(),
            'two_factor_last_step'     => $step,
        ])->save();

        $request->session()->forget('2fa_pending');
        Audit::log('security.2fa_enabled', 'warning', 'user', $user->id);

        return redirect()->route('security.index')->with('success', '2FA ON ho gaya. Ab har login par code maangega.');
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();

        if (! Hash::check($data['password'], $user->password_hash)) {
            Audit::log('security.2fa_disable_failed', 'warning', 'user', $user->id);
            return back()->withErrors(['password' => 'Password galat hai.']);
        }

        $user->forceFill([
            'two_factor_enabled'      => false,
            'two_factor_secret'       => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step'    => null,
        ])->save();

        Audit::log('security.2fa_disabled', 'critical', 'user', $user->id);

        return redirect()->route('security.index')->with('warning', '2FA OFF ho gaya.');
    }

    public function password(Request $request): View
    {
        return view('security.password', ['user' => $request->user()]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password_hash)) {
            Audit::log('security.password_change_failed', 'warning', 'user', $user->id);
            return back()->withErrors(['current_password' => 'Current password galat hai.']);
        }

        $user->forceFill([
            'password_hash'         => Hash::make($data['password']),
            'force_password_change' => false,
        ])->save();

        Audit::log('security.password_changed', 'warning', 'user', $user->id);

        return redirect()->route('dashboard')->with('success', 'Password badal gaya. 👍');
    }

    public function sessions(Request $request): View
    {
        return view('security.sessions', [
            'sessions'      => $this->deviceSessions($request->user()->id),
            'currentSid'    => $request->session()->getId(),
        ]);
    }

    /** Kill one session (cPanel-style "log out other devices"). */
    public function destroySession(Request $request, string $id): RedirectResponse
    {
        $user = $request->user();

        if (! hash_equals($request->session()->getId(), $id)) {
            DB::table('sessions')->where('id', $id)->where('user_id', $user->id)->delete();
            Audit::log('security.session_revoked', 'warning', 'user', $user->id, ['session' => substr($id, 0, 8) . '…']);
        }

        return redirect()->route('security.sessions')->with('success', 'Session band kar di.');
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function deviceSessions(int $userId)
    {
        return DB::table('sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $row): object {
                $row->last_seen = date('Y-m-d H:i:s', (int) $row->last_activity);
                $row->browser   = $this->browser((string) $row->user_agent);
                return $row;
            });
    }

    private function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone')  => 'iPhone',
            str_contains($ua, 'iPad')    => 'iPad',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Edg')     => 'Edge',
            str_contains($ua, 'Chrome')  => 'Chrome',
            str_contains($ua, 'Safari')  => 'Safari',
            str_contains($ua, 'curl')    => 'CLI',
            default                      => 'Browser',
        };
    }
}
