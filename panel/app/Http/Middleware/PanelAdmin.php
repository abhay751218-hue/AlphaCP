<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * WHM (admin) gate — runs after panel.auth.
 * Only superadmin/admin roles may enter the WHM area (User::isAdmin()).
 */
final class PanelAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user instanceof \App\Models\User || !$user->isAdmin()) {
            return redirect()->route('dashboard')
                ->with('error', 'WHM area — admin role required.');
        }

        return $next($request);
    }
}
