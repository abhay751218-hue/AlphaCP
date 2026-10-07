<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webdisk_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('login');
            $table->string('permissions', 4)->default('rw'); // ro | rw
            $table->timestamps();
            $table->unique(['user_id', 'login']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webdisk_accounts');
    }
};
