<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3 — hosting accounts + a default package (S4 owns the packages UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages')) {
            Schema::create('packages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->string('name', 100);
                $table->string('description', 255)->nullable();
                $table->integer('QUOTA')->default(1024);
                $table->integer('BWLIMIT')->default(-1);
                $table->integer('MAXPOP')->default(-1);
                $table->integer('MAXFTP')->default(-1);
                $table->integer('MAXSQL')->default(-1);
                $table->integer('MAXSUB')->default(-1);
                $table->integer('MAXPARK')->default(-1);
                $table->integer('MAXADDON')->default(-1);
                $table->integer('MAXCRON')->default(-1);
                $table->integer('MAXINODE')->default(-1);
                $table->boolean('HASSHELL')->default(false);
                $table->boolean('is_default')->default(false);
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->softDeletes();
                $table->index('owner_id');
            });
        }

        if (! Schema::hasTable('accounts')) {
            Schema::create('accounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('server_id');
                $table->unsignedBigInteger('package_id');
                $table->unsignedBigInteger('reseller_id')->nullable();
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->string('username', 32);
                $table->string('main_domain', 190);
                $table->string('contact_email', 190);
                $table->string('home_path', 255);
                $table->string('php_version', 8)->default('8.4');
                $table->integer('quota_mb')->default(-1);
                $table->string('status', 20)->default('pending');
                $table->string('suspend_reason', 255)->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->timestamp('terminated_at')->nullable();
                $table->timestamp('setup_completed_at')->nullable();
                $table->unsignedBigInteger('disk_used_mb')->default(0);
                $table->unsignedBigInteger('bw_used_mb')->default(0);
                $table->json('meta')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['server_id', 'username'], 'uq_acct_user_server');
                $table->unique('main_domain', 'uq_acct_domain');
                $table->index('status');
                $table->index('reseller_id');
            });
        }

        if (! Schema::hasTable('account_events')) {
            Schema::create('account_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('event', 60);
                $table->string('message', 500)->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['account_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('account_users')) {
            Schema::create('account_users', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->unsignedBigInteger('user_id');
                $table->string('role', 20)->default('owner');
                $table->timestamp('created_at')->nullable();
                $table->unique(['account_id', 'user_id']);
            });
        }

        $now = now();
        if (DB::table('packages')->where('name', 'default')->doesntExist()) {
            DB::table('packages')->insert([
                'name'        => 'default',
                'description' => 'Default hosting package (S3). S4 me limits UI aayegi.',
                'QUOTA'       => 1024,
                'BWLIMIT'     => -1,
                'MAXPOP'      => -1,
                'MAXFTP'      => -1,
                'MAXSQL'      => -1,
                'MAXSUB'      => -1,
                'MAXPARK'     => -1,
                'MAXADDON'    => -1,
                'MAXCRON'     => -1,
                'MAXINODE'    => -1,
                'HASSHELL'    => 0,
                'is_default'  => 1,
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_users');
        Schema::dropIfExists('account_events');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('packages');
    }
};
