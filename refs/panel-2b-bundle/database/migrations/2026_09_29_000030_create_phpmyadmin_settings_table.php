<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 8 — phpMyAdmin enabled flag (JSON under account home). No phpMyAdmin install. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phpmyadmin_settings')) {
            Schema::create('phpmyadmin_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phpmyadmin_settings');
    }
};
