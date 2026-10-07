<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ModuleCatalog;
use Tests\TestCase;

class DashboardSyncTest extends TestCase
{
    /** @return array<string, array<string,mixed>> */
    private function all(): array
    {
        $out = [];
        foreach (ModuleCatalog::sections() as $section) {
            foreach ($section['items'] as $item) {
                $out[$item['name']] = $item;
            }
        }

        return $out;
    }

    public function test_installed_features_are_live(): void
    {
        $all = $this->all();

        foreach ([
            'FTP Accounts'        => 'ftp.index',
            'Git Version Control' => 'git.index',
            'Terminal'            => 'terminal.index',
            'IP Blocker'          => 'ip-blocker.index',
            'ModSecurity'         => 'security-tools.index',
            'Hotlink Protection'  => 'secextra.hotlink',
            'App Installer'       => 'apps.index',
            'API Tokens'          => 'api-tokens.index',
            'Resource Usage'      => 'monitoring.index',
        ] as $name => $route) {
            $this->assertArrayHasKey($name, $all);
            $this->assertSame('live', $all[$name]['status'], "$name should be live");
            $this->assertSame($route, $all[$name]['route'], "$name route");
        }
    }
}
