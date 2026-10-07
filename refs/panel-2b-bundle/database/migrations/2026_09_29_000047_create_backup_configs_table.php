<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — WHM backup schedule/retention (JSON). No tar. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_configs')) {
            Schema::create('backup_configs', function (Blueprint $table): void {
                $table->id();
                $table->string('schedule', 16);
                $table->unsignedSmallInteger('retention');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_configs');
    }
};
