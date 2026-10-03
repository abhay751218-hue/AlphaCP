<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — WHM synchronize DNS records (JSON). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_syncs')) {
            Schema::create('dns_syncs', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 190);
                $table->timestamps();
                $table->unique('domain');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_syncs');
    }
};
