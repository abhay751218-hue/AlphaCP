<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — account-wide email filters (contains-match JSON). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('global_filters')) {
            Schema::create('global_filters', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('domain', 190);
                $table->string('field', 16);
                $table->string('needle', 100);
                $table->string('action', 16);
                $table->string('folder', 32)->default('');
                $table->timestamps();
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('global_filters');
    }
};
