<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — WHM hostname A entry (JSON). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hostname_a_entries')) {
            Schema::create('hostname_a_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('hostname', 190);
                $table->string('ip', 15);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hostname_a_entries');
    }
};
