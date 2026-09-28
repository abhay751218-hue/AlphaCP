<?php
declare(strict_types=1);

use App\Support\AcpEnv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
 * AlphaCP panel bootstrap.
 *
 * AcpEnv::load() pulls DB credentials + server facts from the installer-owned
 * files in /usr/local/alphacp/etc (the same files paneld reads). The panel's own
 * .env holds only app-level settings — never a second copy of credentials.
 */
require_once __DIR__ . '/../app/Support/AcpEnv.php';
AcpEnv::load();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'panel.auth' => App\Http\Middleware\PanelAuth::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->append(App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
