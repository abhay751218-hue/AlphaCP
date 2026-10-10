<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * license_keys.expires_at: TIMESTAMP -> DATETIME NULL.
 * TIMESTAMP sirf 2038 tak jaata hai (Y2038) — lambe customer licenses
 * (10 saal max) aur future-proofing ke liye DATETIME chahiye.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('license_keys') || ! Schema::hasColumn('license_keys', 'expires_at')) {
            return;
        }

        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE `license_keys` MODIFY `expires_at` DATETIME NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        // TIMESTAMP par wapas nahi jaate — DATETIME superset hai, data-safe.
    }
};
