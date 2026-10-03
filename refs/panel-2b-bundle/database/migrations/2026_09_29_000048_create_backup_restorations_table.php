<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — WHM backup restoration full/partial/per-account (JSON). No tar. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_restorations')) {
            Schema::create('backup_restorations', function (Blueprint $table): void {
                $table->id();
                $table->string('mode', 16);
                $table->string('username', 16);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restorations');
    }
};
