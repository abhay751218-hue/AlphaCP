<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — WHM backup configuration (schedule, retention, destination) as JSON. No tar/shell. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_configs')) {
            Schema::create('backup_configs', function (Blueprint $table): void {
                $table->id();
                $table->boolean('enabled')->default(true);
                $table->string('schedule', 16)->default('daily');
                $table->unsignedSmallInteger('retention_days')->default(30);
                $table->string('destination', 16)->default('local');
                $table->string('remote_host', 190)->nullable();
                $table->string('remote_user', 64)->nullable();
                $table->string('remote_path', 190)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_configs');
    }
};
