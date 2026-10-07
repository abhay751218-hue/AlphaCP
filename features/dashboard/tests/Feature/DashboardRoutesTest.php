<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Har tile jo patched ModuleCatalog me 'live' hai (hamare features) — uska route
 * register hona chahiye, warna dashboard customer ke saamne "Route not defined" dega.
 */
class DashboardRoutesTest extends TestCase
{
    public function test_all_flipped_feature_routes_resolve(): void
    {
        $routes = [
            'ftp.index', 'git.index', 'terminal.index', 'metrics.index',
            'monitoring.index', 'ip-blocker.index', 'security-tools.index',
            'secextra.hotlink', 'secextra.leech', 'apps.index', 'api-tokens.index',
            'resellers.index', 'license-server.index', 'webdisk.index',
            'images.index', 'trash.index', 'optimize.index',
        ];

        foreach ($routes as $name) {
            $this->assertTrue(
                app('router')->has($name) || $this->hasRouteName($name),
                "Route [$name] register nahi hai — dashboard tile tootega!",
            );
        }
    }

    private function hasRouteName(string $name): bool
    {
        return array_key_exists($name, app('router')->getRoutes()->getRoutesByName());
    }
}
