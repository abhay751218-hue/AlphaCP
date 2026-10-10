<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks panel routes until the user's second factor is verified for this
 * session. Login sets `two_factor_passed` when the user has no 2FA configured
 * OR after a correct TOTP code.
 *
 * LOOP FIX (v2): agar account par 2FA enabled hi nahi hai par session me
 * `two_factor_passed` kho gaya hai (purana session, admin ne 2FA off kiya,
 * session driver badla, ya login ke beech ka ek adhoora request), to ye
 * middleware /two-factor par bhejta tha aur TwoFactorController::challenge()
 * wahan se /dashboard par — matlab /dashboard <-> /two-factor ka INFINITE
 * REDIRECT LOOP. Browser me yahi "login nahi ho raha, error aa raha" dikhta
 * hai (ERR_TOO_MANY_REDIRECTS). Ab flag khud heal ho jata hai.
 */
class EnsureTwoFactorIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 2FA account par hai hi nahi -> challenge ka koi matlab nahi.
        if ($user !== null && ! $user->two_factor_enabled) {
            $request->session()->put('two_factor_passed', true);
        }

        if (! $request->session()->get('two_factor_passed', false)) {
            return redirect()->route('twofactor.challenge');
        }

        return $next($request);
    }
}
