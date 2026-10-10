<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — customer backup wizard plan (JSON under account home). No tar. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_wizards')) {
            Schema::create('backup_wizards', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('action', 16);
                $table->string('scope', 16);
                $table->timestamps();
                $table->unique('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_wizards');
    }
};
