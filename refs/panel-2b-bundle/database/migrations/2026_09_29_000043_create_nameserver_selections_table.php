<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — WHM nameserver selection (JSON). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nameserver_selections')) {
            Schema::create('nameserver_selections', function (Blueprint $table): void {
                $table->id();
                $table->string('software', 16);
                $table->string('ns1', 190);
                $table->string('ns2', 190);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nameserver_selections');
    }
};
