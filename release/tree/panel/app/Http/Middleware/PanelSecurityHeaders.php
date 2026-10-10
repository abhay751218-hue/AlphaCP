<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Defence-in-depth headers for every panel response (cPanel does the same). */
class PanelSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Clickjacking: DENY by default; 'OFF' is only for dev installs that are
        // embedded in a preview iframe (see config/acp.php).
        $frameOptions = strtoupper((string) config('acp.security.frame_options', 'DENY'));
        if ($frameOptions !== 'OFF') {
            $response->headers->set('X-Frame-Options', $frameOptions);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
            . "script-src 'self'; form-action 'self'; frame-ancestors "
            . ($frameOptions === 'OFF' ? '*' : ($frameOptions === 'SAMEORIGIN' ? "'self'" : "'none'"))
        );

        return $response;
    }
}
