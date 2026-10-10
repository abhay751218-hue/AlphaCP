<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 8 — real MariaDB users and their per-database privileges (panel 0.70.0).
 *
 * The panel only books what the customer asked for; the root agent creates the
 * actual MariaDB objects (`db.user.create`, `db.user.grant`, `db.user.drop`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mysql_users')) {
            Schema::create('mysql_users', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('name', 16);
                $table->string('host', 190)->default('localhost');
                $table->timestamps();
                $table->unique(['account_id', 'name', 'host'], 'uq_mysql_user');
                $table->index('account_id');
            });
        }

        if (! Schema::hasTable('mysql_user_grants')) {
            Schema::create('mysql_user_grants', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('mysql_user_id');
                $table->unsignedBigInteger('mysql_database_id');
                $table->timestamps();
                $table->unique(['mysql_user_id', 'mysql_database_id'], 'uq_mysql_grant');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mysql_user_grants');
        Schema::dropIfExists('mysql_users');
    }
};
