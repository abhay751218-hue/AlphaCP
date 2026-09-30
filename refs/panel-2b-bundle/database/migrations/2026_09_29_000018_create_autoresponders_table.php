<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — vacation autoresponders (JSON under account home). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('autoresponders')) {
            Schema::create('autoresponders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('localpart', 32);
                $table->string('domain', 190);
                $table->string('subject', 200);
                $table->text('body');
                $table->unsignedSmallInteger('interval_h')->default(24);
                $table->timestamps();
                $table->unique(['account_id', 'localpart', 'domain']);
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('autoresponders');
    }
};
