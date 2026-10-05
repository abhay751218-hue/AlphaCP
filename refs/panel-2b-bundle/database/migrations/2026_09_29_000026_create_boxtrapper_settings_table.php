<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — BoxTrapper enabled + allowlist (JSON under account home). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('box_trapper_settings')) {
            Schema::create('box_trapper_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->json('allowlist')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('box_trapper_settings');
    }
};
