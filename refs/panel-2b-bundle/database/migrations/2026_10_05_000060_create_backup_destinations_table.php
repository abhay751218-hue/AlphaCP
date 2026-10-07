<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 10 — remote backup destinations.
 *
 * The panel only ever keeps the DESCRIPTION of a destination (where to push and
 * with which host key pin). The credential itself never reaches the database:
 * the agent keeps the private key / password in a 0600 file of its own and the
 * panel only says "key" or "password".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_destinations')) {
            Schema::create('backup_destinations', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 32)->unique();
                $table->string('type', 8)->default('ssh');
                $table->string('host', 253);
                $table->unsignedSmallInteger('port')->default(22);
                $table->string('username', 32);
                $table->string('path', 255);
                $table->string('auth_type', 8)->default('key');
                $table->unsignedSmallInteger('retention_days')->default(30);
                $table->string('host_fingerprint', 128)->nullable();
                $table->text('public_key')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamp('last_test_at')->nullable();
                $table->boolean('last_test_ok')->nullable();
                $table->string('last_test_message', 500)->nullable();
                $table->timestamp('last_push_at')->nullable();
                $table->string('last_push_message', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_destinations');
    }
};
