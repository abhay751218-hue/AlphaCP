<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — Webmail preferred client (JSON under account home). No Roundcube. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webmail_settings')) {
            Schema::create('webmail_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->string('client', 16)->default('roundcube');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_settings');
    }
};
