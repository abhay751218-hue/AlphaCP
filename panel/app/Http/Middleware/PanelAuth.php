<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel auth gate.
 *   - not logged in  -> redirect to /login
 *   - suspended user -> logout + message
 * Slice 1 keeps this deliberately boring and explicit (RBAC checks come with
 * the permission map in Step 2B-2).
 */
final class PanelAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Pehle login karein.');
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('error', 'Ye account suspended hai — support se baat karein.');
        }

        return $next($request);
    }
}
