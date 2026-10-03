<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — Calendar + contact names (JSON under account home). No CalDAV. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_items')) {
            Schema::create('calendar_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('kind', 16);
                $table->string('name', 64);
                $table->timestamps();
                $table->unique(['account_id', 'kind', 'name']);
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_items');
    }
};
