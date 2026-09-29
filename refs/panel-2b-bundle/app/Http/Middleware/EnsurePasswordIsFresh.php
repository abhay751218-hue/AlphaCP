<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * cPanel-style "force password change": if the account was created with a
 * temporary password (or an admin forced a reset), every panel page redirects
 * to the password change screen until it is done.
 */
class EnsurePasswordIsFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->force_password_change
            && ! $request->routeIs('security.password', 'security.password.update', 'logout')) {
            return redirect()->route('security.password')
                ->with('warning', 'Pehle apna password badalna zaroori hai (security policy).');
        }

        return $next($request);
    }
}
