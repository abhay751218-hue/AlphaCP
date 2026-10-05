<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — spam score + blacklist/whitelist. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('spam_settings')) {
            Schema::create('spam_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id')->unique();
                $table->unsignedTinyInteger('required_score')->default(5);
                $table->json('blacklist')->nullable();
                $table->json('whitelist')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('spam_settings');
    }
};
