<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AlphaCP panel core schema (Step 2B).
 *
 * NOTE: agent-side tables (servers, tasks, task_logs, audit_logs, settings,
 * system_events, updates_history, schema_migrations) are created by
 * db/migrations/0001_core.sql, which the Step-2 installer applies BEFORE this
 * migration runs. From Step 2B onward, every schema change is a Laravel
 * migration in panel/database/migrations.
 *
 * Field names follow docs/02-database-schema.sql (the frozen spec).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();          // root | reseller | user | mail
            $table->string('label', 100);
            $table->unsignedTinyInteger('level')->default(3); // 1=root 2=reseller 3=user 4=mail
            $table->boolean('is_system')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();          // e.g. accounts.manage
            $table->string('module', 50);
            $table->string('label', 150);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
            $table->index('module');
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission_key', 100);
            $table->unique(['role_id', 'permission_key']);
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 64)->unique();
            $table->string('email', 190)->nullable()->unique();
            $table->string('password_hash');
            $table->string('full_name', 190)->nullable();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();

            $table->enum('status', ['active', 'suspended', 'locked'])->default('active');
            $table->boolean('force_password_change')->default(false);

            $table->text('two_factor_secret')->nullable();   // Laravel Crypt (AES-256-GCM)
            $table->boolean('two_factor_enabled')->default(false);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedInteger('two_factor_last_step')->nullable(); // replay protection

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('theme', 20)->default('dark');

            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // Laravel database session driver (also powers "Active Sessions" UI)
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // cPHulk-lite: login throttling + lockout evidence
        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 64)->nullable();
            $table->string('ip', 45);
            $table->boolean('success')->default(false);
            $table->string('reason', 60)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ip', 'created_at']);
            $table->index(['username', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
