<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** S7 #18: persist static subscribers for real Exim list fan-out. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailing_lists') && ! Schema::hasColumn('mailing_lists', 'members')) {
            Schema::table('mailing_lists', function (Blueprint $table): void {
                $table->json('members')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mailing_lists') && Schema::hasColumn('mailing_lists', 'members')) {
            Schema::table('mailing_lists', function (Blueprint $table): void {
                $table->dropColumn('members');
            });
        }
    }
};
