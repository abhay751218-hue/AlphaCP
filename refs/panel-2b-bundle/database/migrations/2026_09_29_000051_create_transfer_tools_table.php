<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — WHM cPanel→AlphaCP transfer (JSON). No tar/rsync. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfer_tools')) {
            Schema::create('transfer_tools', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 16);
                $table->string('source', 190);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_tools');
    }
};
