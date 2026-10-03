<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — WHM backup user selection (JSON). No tar. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_user_selections')) {
            Schema::create('backup_user_selections', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 16);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_user_selections');
    }
};
