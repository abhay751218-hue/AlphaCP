<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_cluster_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('hostname');
            $table->string('ip', 45);
            $table->string('role', 12)->default('dns'); // dns | ns
            $table->string('status', 12)->default('added'); // added|synced
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique('hostname');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_cluster_nodes');
    }
};
