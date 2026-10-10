<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** S10 — cPanel import ke saath mysql/*.sql dumps bhi restore karne ka option. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfer_restores') || Schema::hasColumn('transfer_restores', 'mysql')) {
            return;
        }
        Schema::table('transfer_restores', function (Blueprint $table): void {
            $table->boolean('mysql')->default(false)->after('action');
            $table->string('mysql_only', 255)->nullable()->after('mysql');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transfer_restores') || ! Schema::hasColumn('transfer_restores', 'mysql')) {
            return;
        }
        Schema::table('transfer_restores', function (Blueprint $table): void {
            $table->dropColumn(['mysql', 'mysql_only']);
        });
    }
};
