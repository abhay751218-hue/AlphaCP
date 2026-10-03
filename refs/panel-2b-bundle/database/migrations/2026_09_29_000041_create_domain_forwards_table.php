<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — WHM domain forwarding (JSON). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('domain_forwards')) {
            Schema::create('domain_forwards', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 190);
                $table->string('url', 255);
                $table->unsignedSmallInteger('code');
                $table->timestamps();
                $table->unique('domain');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_forwards');
    }
};
