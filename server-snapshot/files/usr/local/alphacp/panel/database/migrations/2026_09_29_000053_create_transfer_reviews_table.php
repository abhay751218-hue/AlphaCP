<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — WHM review transfers and restores (JSON). No tar/rsync. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfer_reviews')) {
            Schema::create('transfer_reviews', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 16);
                $table->string('status', 16);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_reviews');
    }
};
