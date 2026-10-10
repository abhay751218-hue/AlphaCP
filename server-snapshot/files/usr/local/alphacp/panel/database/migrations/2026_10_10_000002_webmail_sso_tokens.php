<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D24 — webmail_sso_tokens table (webmail one-click SSO).
 * AWS par ye table live-fix se bani thi (migration ke bina) — fresh installs
 * par missing thi jisse /webmail/open 500 deta tha. hasTable guard se AWS
 * par bhi safe (sirf migration record add hota hai).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webmail_sso_tokens')) {
            return;
        }
        Schema::create('webmail_sso_tokens', function (Blueprint $table): void {
            $table->id();
            $table->char('token', 64)->unique('uq_webmail_sso_token');
            $table->unsignedBigInteger('user_id');
            $table->string('mailbox');
            $table->boolean('used')->default(false);
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'used'], 'idx_webmail_sso_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_sso_tokens');
    }
};
