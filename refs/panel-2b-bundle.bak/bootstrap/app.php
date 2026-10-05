<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePasswordIsFresh;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Panel login rate limit (per IP): name maps to throttle:login in routes.
        $middleware->alias([
            '2fa'            => EnsureTwoFactorIsVerified::class,
            'password.fresh' => EnsurePasswordIsFresh::class,
            'perm'           => EnsurePermission::class,
            'throttle'       => ThrottleRequests::class,
        ]);

        $middleware->throttleWithRedis(false);

        // Trust the local reverse proxy / tunnel so $request->ip() is honest.
        $middleware->trustProxies(at: '*');

        // Security headers on every panel response.
        $middleware->append(\App\Http\Middleware\PanelSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
