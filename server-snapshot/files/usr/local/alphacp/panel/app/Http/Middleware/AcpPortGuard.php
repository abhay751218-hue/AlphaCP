<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ModuleCatalog;
use App\Support\PortMap;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Ek panel = ek port" enforcement (OWNER-CTRL slice).
 *
 * Request port se panel-family lock hoti hai:
 *  - 2087 (whm)    → sirf WHM-mode users (root/reseller)
 *  - 2083 (cpanel) → sirf cPanel-mode users (customers)
 *  - 2096/8090     → alag vhost (Roundcube / static link-page) — PHP yahan nahi
 *  - unknown port  → pass (dev/preview/sim)
 *
 * Galat family ka logged-in user mile to session khatam karke sahi port ke
 * login par bhej do — cPanel company jaisi strict separation.
 */
final class AcpPortGuard
{
    public function handle(Request $request, Closure $next): mixed
    {
        $family = PortMap::familyFor((int) $request->getPort());

        if ($family === null || $family === 'webmail' || $family === 'link') {
            return $next($request);
        }

        $user = $request->user();
        if ($user !== null) {
            $mode = ModuleCatalog::modeFor($user);
            if ($mode !== $family) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->to(PortMap::loginUrl($mode))->withErrors([
                    'username' => sprintf(
                        'Aapka panel port %d par khulta hai — wahan login karein.',
                        PortMap::portFor($mode),
                    ),
                ]);
            }
        }

        return $next($request);
    }
}
