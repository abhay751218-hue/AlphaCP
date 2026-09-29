<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstWipingTheProductionDatabase();
    }

    /**
     * The suite uses RefreshDatabase, which DROPS every table. Running it
     * against the live panel database would delete a customer's panel.
     *
     * This really happened during installer testing: on a server the panel runs
     * with a cached config (`php artisan config:cache`), and a cached config
     * ignores phpunit.xml — so `php artisan test` connected to the production
     * database. Clear the cache before testing and this guard stays quiet.
     */
    private function guardAgainstWipingTheProductionDatabase(): void
    {
        $connection = (string) config('database.default');
        $database   = (string) config("database.connections.{$connection}.database");
        $expected   = (string) (env('ACP_TEST_DATABASE') ?: 'alphacp_test');

        if ($database === $expected) {
            return;
        }

        throw new RuntimeException(
            "Refusing to run tests against database '{$database}' — expected '{$expected}'.\n" .
            "The suite wipes tables (RefreshDatabase). A cached config ignores phpunit.xml,\n" .
            "so run:  php artisan config:clear   and try again."
        );
    }
}
