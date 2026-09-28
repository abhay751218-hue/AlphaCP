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
 */
class EnsureTwoFactorIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('two_factor_passed', false)) {
            return redirect()->route('twofactor.challenge');
        }

        return $next($request);
    }
}
