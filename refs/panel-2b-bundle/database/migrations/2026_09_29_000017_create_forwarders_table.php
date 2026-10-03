<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — email forwarders (address → address). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forwarders')) {
            Schema::create('forwarders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('localpart', 32);
                $table->string('domain', 190);
                $table->string('dest', 190);
                $table->timestamps();
                $table->unique(['account_id', 'localpart', 'domain']);
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('forwarders');
    }
};
