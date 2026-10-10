<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 5 — AutoSSL include flag + last error on domains. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('domains') && ! Schema::hasColumn('domains', 'ssl_autossl')) {
            Schema::table('domains', function (Blueprint $table): void {
                $table->boolean('ssl_autossl')->default(true);
                $table->string('ssl_last_error', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('domains') && Schema::hasColumn('domains', 'ssl_autossl')) {
            Schema::table('domains', function (Blueprint $table): void {
                $table->dropColumn(['ssl_autossl', 'ssl_last_error']);
            });
        }
    }
};
