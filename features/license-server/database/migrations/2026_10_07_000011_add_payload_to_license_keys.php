<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('license_keys', 'license_uid')) {
                $table->string('license_uid', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('license_keys', 'payload')) {
                $table->json('payload')->nullable();
            }
            if (! Schema::hasColumn('license_keys', 'signature')) {
                $table->text('signature')->nullable();
            }
            if (! Schema::hasColumn('license_keys', 'sig_algo')) {
                $table->string('sig_algo', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('license_keys', function (Blueprint $table): void {
            foreach (['license_uid', 'payload', 'signature', 'sig_algo'] as $column) {
                if (Schema::hasColumn('license_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
