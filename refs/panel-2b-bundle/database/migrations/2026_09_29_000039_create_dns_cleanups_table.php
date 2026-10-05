<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — WHM DNS cleanup (JSON). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_cleanups')) {
            Schema::create('dns_cleanups', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 190);
                $table->timestamps();
                $table->unique('domain');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_cleanups');
    }
};
