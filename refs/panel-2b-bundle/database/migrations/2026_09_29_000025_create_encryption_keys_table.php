<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — GnuPG identity rows (JSON under account home). No private keys. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('encryption_keys')) {
            Schema::create('encryption_keys', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('localpart', 32);
                $table->string('domain', 190);
                $table->string('comment', 100);
                $table->timestamps();
                $table->unique(['account_id', 'localpart', 'domain']);
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('encryption_keys');
    }
};
