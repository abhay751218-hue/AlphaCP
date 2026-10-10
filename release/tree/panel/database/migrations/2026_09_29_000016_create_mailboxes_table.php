<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7 — virtual mailboxes (Email Accounts). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mailboxes')) {
            Schema::create('mailboxes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('localpart', 32);
                $table->string('domain', 190);
                $table->integer('quota_mb')->default(1024);
                $table->string('password_hash', 72);
                $table->string('status', 20)->default('pending');
                $table->timestamps();
                $table->unique(['account_id', 'localpart', 'domain']);
                $table->index('account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
