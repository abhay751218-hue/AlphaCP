<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — WHM set zone TTL (JSON). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zone_ttls')) {
            Schema::create('zone_ttls', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 190);
                $table->unsignedInteger('ttl');
                $table->timestamps();
                $table->unique('domain');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_ttls');
    }
};
