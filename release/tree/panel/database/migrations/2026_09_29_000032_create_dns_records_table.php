<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Step 9 — Zone Editor records (JSON under account home). No BIND rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_records')) {
            Schema::create('dns_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('domain', 190);
                $table->string('name', 63);
                $table->string('type', 8);
                $table->string('value', 255);
                $table->timestamps();
                $table->unique(['account_id', 'domain', 'name', 'type']);
                $table->index('account_id');
            });
        }

        if (! DB::table('permissions')->where('key', 'dns.manage')->exists()) {
            DB::table('permissions')->insert([
                'key' => 'dns.manage',
                'module' => 'dns',
                'label' => 'Edit DNS records',
                'sort' => 2010,
            ]);
        }

        foreach (['user', 'reseller'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $roleId) {
                continue;
            }
            $has = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_key', 'dns.manage')
                ->exists();
            if (! $has) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_key' => 'dns.manage',
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
        foreach (['user', 'reseller'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if ($roleId) {
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_key', 'dns.manage')
                    ->delete();
            }
        }
    }
};
