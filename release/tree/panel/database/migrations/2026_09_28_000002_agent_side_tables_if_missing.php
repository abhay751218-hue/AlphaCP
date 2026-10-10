<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent-side tables (tasks, audit_logs, servers …).
 *
 * On a real server these are created by installer/step2-install.sh
 * (db/migrations/0001_core.sql) BEFORE this runs, so every block below is a
 * no-op there. On a fresh developer machine / test database they are created
 * here, which keeps `php artisan migrate` a complete, honest way to build the
 * whole schema.
 *
 * Column definitions MUST stay in sync with db/migrations/0001_core.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('servers')) {
            Schema::create('servers', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('hostname', 190)->unique();
                $table->enum('role', ['central', 'node'])->default('central');
                $table->string('public_ip', 45)->nullable();
                $table->string('private_ip', 45)->nullable();
                $table->string('os', 100)->nullable();
                $table->enum('arch', ['x86_64', 'aarch64'])->nullable();
                $table->string('panel_version', 20)->nullable();
                $table->enum('status', ['active', 'maintenance', 'offline'])->default('active');
                $table->string('fingerprint', 128)->nullable();
                $table->string('agent_token_hash')->nullable();
                $table->json('specs')->nullable();
                $table->unsignedBigInteger('license_id')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->id();
                $table->enum('scope', ['global', 'server', 'user', 'account'])->default('global');
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->string('key_name', 100);
                $table->json('value')->nullable();
                $table->timestamps();
                $table->unique(['scope', 'scope_id', 'key_name'], 'uq_settings');
            });
        }

        if (! Schema::hasTable('system_events')) {
            Schema::create('system_events', function (Blueprint $table): void {
                $table->id();
                $table->string('type', 60);
                $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
                $table->string('title', 190);
                $table->text('body')->nullable();
                $table->string('target_type', 50)->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->boolean('is_read')->default(false);
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['type', 'created_at']);
                $table->index('is_read');
            });
        }

        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('server_id');
                $table->string('type', 80);
                $table->enum('safety', ['readonly', 'mutating', 'destructive']);
                $table->json('payload');
                $table->unsignedTinyInteger('priority')->default(100);
                $table->unsignedBigInteger('account_id')->nullable();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->string('requested_src', 40)->default('cli');
                $table->enum('status', ['queued', 'running', 'success', 'failed', 'cancelled'])->default('queued');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->unsignedTinyInteger('max_attempts')->default(3);
                $table->json('result')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamps();
                $table->index(['server_id', 'status', 'priority', 'id'], 'idx_task_pick');
                $table->index('account_id', 'idx_task_acct');
            });
        }

        if (! Schema::hasTable('task_logs')) {
            Schema::create('task_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_id');
                $table->enum('level', ['debug', 'info', 'warning', 'error'])->default('info');
                $table->text('line');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['task_id', 'id'], 'idx_tlog_task');
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->enum('actor_type', ['user', 'api', 'system', 'agent']);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_ip', 45)->nullable();
                $table->string('action', 100);
                $table->string('target_type', 50)->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['action', 'created_at'], 'idx_audit_action');
                $table->index(['target_type', 'target_id'], 'idx_audit_target');
                $table->index(['actor_type', 'actor_id'], 'idx_audit_actor');
            });
        }

        if (! Schema::hasTable('updates_history')) {
            Schema::create('updates_history', function (Blueprint $table): void {
                $table->id();
                $table->string('from_version', 20)->nullable();
                $table->string('to_version', 20);
                $table->enum('channel', ['stable', 'beta'])->default('stable');
                $table->enum('status', ['started', 'success', 'failed', 'rolled_back'])->default('started');
                $table->unsignedBigInteger('triggered_by')->nullable();
                $table->string('log_path', 255)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // These tables belong to the installer; dropping them here could destroy
        // real data. Intentionally left alone.
    }
};
