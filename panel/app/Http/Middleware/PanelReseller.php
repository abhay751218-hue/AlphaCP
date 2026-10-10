<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reseller gate — runs after panel.auth.
 * Reseller + admin roles may enter the reseller panel (admins manage resellers).
 */
final class PanelReseller
{
    private const ALLOWED = ['superadmin', 'admin', 'reseller'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user instanceof \App\Models\User || !in_array($user->roleSlug(), self::ALLOWED, true)) {
            return redirect()->route('dashboard')
                ->with('error', 'Reseller area — reseller role required.');
        }

        return $next($request);
    }
}
