<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The panel boots differently on a server than on a dev machine:
 * on a server .env has NO DB_* values (they live in etc/panel.env), so any
 * config file that only works when those env vars are set breaks every install.
 *
 * This test boots a fresh PHP process with those variables removed — exactly
 * the production situation. It caught a real "Class \"PanelEnv\" not found"
 * install failure, so please keep it.
 */
class ConfigBootTest extends TestCase
{
    /** @return array{0: string, 1: int, 2: string} */
    private function bootWithoutDbEnv(): array
    {
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo "\nRESULT:" . json_encode([
            'database' => config('database.connections.mysql.database'),
            'driver'   => config('database.default'),
            'server'   => config('acp.server_id'),
        ]);
        PHP;

        $cmd = sprintf(
            'cd %s && env -u DB_DATABASE -u DB_USERNAME -u DB_PASSWORD -u DB_HOST -u DB_CONNECTION %s -r %s 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($code),
        );

        exec($cmd, $lines, $exit);
        $output = implode("\n", $lines);

        // the child also writes warnings to stderr (merged above) — take the
        // marked JSON line so a warning can never break the parse
        $result = '';
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), 'RESULT:')) {
                $result = trim(substr(trim($line), 7));
            }
        }

        return [$result, $exit, $output];
    }

    public function test_config_boots_without_db_env_vars(): void
    {
        [$json, $exit, $raw] = $this->bootWithoutDbEnv();

        $this->assertStringNotContainsString('not found', $raw, "config failed to boot: {$raw}");
        $this->assertStringNotContainsString('Fatal error', $raw, "config failed to boot: {$raw}");
        $this->assertSame(0, $exit, "config boot exited {$exit}: {$raw}");

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, "unexpected output: {$raw}");
        $this->assertNotEmpty($decoded['database'], 'database name must resolve without DB_DATABASE');
        $this->assertSame('mysql', $decoded['driver']);
        $this->assertGreaterThan(0, (int) $decoded['server'], 'server id must resolve');
    }
}
