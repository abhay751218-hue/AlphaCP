<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5 — per-domain MultiPHP INI storage (docs/09 rows 68/69).
 *
 * `domains.php_version` (2026_09_29_000005) already stores the per-domain PHP
 * version; this adds the per-domain INI directives for that domain's own
 * FPM pool. Additive only: no column is renamed or removed (ADR / AGENTS rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('domains') || Schema::hasColumn('domains', 'php_ini')) {
            return;
        }

        Schema::table('domains', function (Blueprint $table): void {
            $table->json('php_ini')->nullable()->after('php_version');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('domains') && Schema::hasColumn('domains', 'php_ini')) {
            Schema::table('domains', function (Blueprint $table): void {
                $table->dropColumn('php_ini');
            });
        }
    }
};
