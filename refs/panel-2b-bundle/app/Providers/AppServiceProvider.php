<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Behind the installer's TLS/proxy the panel must generate https URLs.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // ---- RBAC ----------------------------------------------------------
        // Root (role level 1) can do everything; everyone else is checked
        // against the permission keys in role_permissions.
        Gate::before(static function (User $user): ?bool {
            return $user->isRoot() ? true : null;
        });

        foreach (PermissionCatalog::all() as $key => $_) {
            Gate::define($key, static fn (User $user): bool => $user->hasPermission($key));
        }

        // ---- Password policy (single source of truth) -----------------------
        Password::defaults(static function (): Password {
            $rule = Password::min((int) config('acp.security.password_min_length', 10))
                ->letters()
                ->mixedCase()
                ->numbers();

            // Breach check is on by default; see config/acp.php for why it can
            // be switched off on hosts with no outbound internet.
            return config('acp.password_check_pwned', true) ? $rule->uncompromised() : $rule;
        });

        // Keep the breach check from stalling a password change for 30s when
        // api.pwnedpasswords.com is unreachable (Laravel's default timeout).
        $this->app->singleton(
            \Illuminate\Contracts\Validation\UncompromisedVerifier::class,
            static fn ($app) => new \Illuminate\Validation\NotPwnedVerifier(
                $app[\Illuminate\Contracts\Http\Client\Factory::class],
                (int) config('acp.password_pwned_timeout', 3),
            ),
        );

        // ---- Named rate limiters ---------------------------------------------
        \Illuminate\Support\Facades\RateLimiter::for('login', static function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(
                (int) config('acp.security.throttle_per_minute', 10)
            )->by($request->ip());
        });

        // ---- Shared panel layout data ---------------------------------------
        // Every authenticated page uses the same header. Supplying the server
        // row here prevents pages such as User Manager/Audit from showing
        // `server: unknown` merely because their controller is not a dashboard.
        View::composer('layouts.panel', static function ($view): void {
            $user = auth()->user();
            $view->with('server', \App\Support\Panel::server());
            $view->with('panelMode', $user ? \App\Support\ModuleCatalog::modeFor($user) : 'cpanel');
        });

        // ---- Blade helpers ---------------------------------------------------
        \Illuminate\Support\Facades\Blade::directive('ago', static function (string $expression): string {
            return "<?php echo e(\App\Support\Panel::ago($expression)); ?>";
        });
    }
}
