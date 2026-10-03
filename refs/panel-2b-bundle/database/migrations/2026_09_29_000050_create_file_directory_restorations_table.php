<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 10 — WHM file/directory restoration (JSON). No tar. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('file_directory_restorations')) {
            Schema::create('file_directory_restorations', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 16);
                $table->string('path', 240);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_directory_restorations');
    }
};
