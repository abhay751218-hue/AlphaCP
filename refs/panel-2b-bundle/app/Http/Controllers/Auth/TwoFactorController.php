<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/** Second step of login: 6-digit TOTP code (Google Authenticator style). */
class TwoFactorController extends Controller
{
    public function challenge(): View|RedirectResponse
    {
        $user = request()->user();

        if (! $user) {
            return redirect()->route('login');
        }
        if (! $user->two_factor_enabled || request()->session()->get('two_factor_passed')) {
            request()->session()->put('two_factor_passed', true);   // loop-breaker (login-fix v1.0)
            return redirect()->route('dashboard');
        }

        return view('auth.twofactor');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $secret = Crypt::decryptString((string) $user->two_factor_secret);

        $step = Totp::verify($secret, $data['code'], $user->two_factor_last_step);

        if ($step === null) {
            Audit::log('auth.2fa_failed', 'warning', 'user', $user->id);
            return back()->withErrors(['code' => 'The code is wrong or expired. Try again.']);
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();
        $request->session()->put('two_factor_passed', true);
        Audit::log('auth.2fa_passed', 'info', 'user', $user->id);

        return redirect()->intended(route('dashboard'));
    }
}
