<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — WHM global email routing (JSON). No Exim rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('global_email_routes')) {
            Schema::create('global_email_routes', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 190);
                $table->string('mode', 16);
                $table->timestamps();
                $table->unique('domain');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('global_email_routes');
    }
};
