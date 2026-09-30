<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — per-mailbox email filters (contains-match JSON). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_filters')) {
            Schema::create('mail_filters', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('localpart', 32);
                $table->string('domain', 190);
                $table->string('field', 16);
                $table->string('needle', 100);
                $table->string('action', 16);
                $table->string('folder', 32)->default('');
                $table->timestamps();
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_filters');
    }
};
