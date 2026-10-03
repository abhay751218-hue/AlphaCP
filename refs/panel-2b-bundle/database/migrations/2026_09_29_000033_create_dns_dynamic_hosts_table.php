<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9 — Dynamic DNS hosts (JSON under account home). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_dynamic_hosts')) {
            Schema::create('dns_dynamic_hosts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('domain', 190);
                $table->string('name', 63);
                $table->string('token', 32);
                $table->string('ip', 15)->default('');
                $table->timestamps();
                $table->unique(['account_id', 'domain', 'name']);
                $table->unique('token');
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_dynamic_hosts');
    }
};
