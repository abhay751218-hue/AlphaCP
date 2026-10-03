<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — default address (catch-all per domain). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catchalls')) {
            Schema::create('catchalls', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('domain', 190);
                $table->string('dest', 190);
                $table->timestamps();
                $table->unique(['account_id', 'domain']);
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catchalls');
    }
};
