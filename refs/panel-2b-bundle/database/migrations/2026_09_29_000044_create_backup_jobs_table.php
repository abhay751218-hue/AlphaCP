<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — customer backup job list (JSON under account home). No tar. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_jobs')) {
            Schema::create('backup_jobs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('kind', 16);
                $table->string('path', 240)->default('');
                $table->timestamps();
                $table->unique(['account_id', 'kind', 'path']);
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_jobs');
    }
};
