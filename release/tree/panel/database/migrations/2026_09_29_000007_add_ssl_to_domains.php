<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Step 5 — SSL status columns + customer ssl.view/manage. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('domains') && ! Schema::hasColumn('domains', 'ssl_status')) {
            Schema::table('domains', function (Blueprint $table): void {
                $table->string('ssl_status', 20)->default('none');
                $table->string('ssl_issuer', 40)->nullable();
                $table->timestamp('ssl_not_after')->nullable();
            });
        }

        $sort = 950;
        foreach ([
            'ssl.view' => ['ssl', 'View SSL status'],
            'ssl.manage' => ['ssl', 'Issue/remove SSL certificates'],
        ] as $key => [$module, $label]) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert([
                    'key' => $key, 'module' => $module, 'label' => $label, 'sort' => $sort,
                ]);
            }
            $sort += 10;
        }

        foreach (['user', 'reseller'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (['ssl.view', 'ssl.manage'] as $key) {
                $has = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_key', $key)
                    ->exists();
                if (! $has) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_key' => $key,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('domains') && Schema::hasColumn('domains', 'ssl_status')) {
            Schema::table('domains', function (Blueprint $table): void {
                $table->dropColumn(['ssl_status', 'ssl_issuer', 'ssl_not_after']);
            });
        }
    }
};
