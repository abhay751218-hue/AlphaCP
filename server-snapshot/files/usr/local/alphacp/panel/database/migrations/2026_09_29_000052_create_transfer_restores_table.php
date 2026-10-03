<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — WHM transfer/restore a cPanel account (JSON). No tar/rsync. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfer_restores')) {
            Schema::create('transfer_restores', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 16);
                $table->string('action', 16);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_restores');
    }
};
