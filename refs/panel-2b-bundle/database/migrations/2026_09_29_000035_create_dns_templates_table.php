<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — WHM zone templates (JSON). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_templates')) {
            Schema::create('dns_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 32);
                $table->string('body', 2000);
                $table->timestamps();
                $table->unique('name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_templates');
    }
};
