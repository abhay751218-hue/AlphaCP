<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — WHM nameserver record report (JSON). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ns_records')) {
            Schema::create('ns_records', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 190);
                $table->string('nameserver', 190);
                $table->timestamps();
                $table->unique(['domain', 'nameserver']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ns_records');
    }
};
