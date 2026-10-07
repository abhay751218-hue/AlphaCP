<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 8 — Remote MySQL access hosts (JSON under account home). No GRANT. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mysql_remote_hosts')) {
            Schema::create('mysql_remote_hosts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('host', 190);
                $table->timestamps();
                $table->unique(['account_id', 'host']);
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mysql_remote_hosts');
    }
};
