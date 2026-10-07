<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Step 5 — grant Error Pages perms to customer + reseller. */
return new class extends Migration
{
    public function up(): void
    {
        $sort = 970;
        foreach ([
            'errorpages.view' => ['errorpages', 'View error pages'],
            'errorpages.manage' => ['errorpages', 'Edit custom error pages'],
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
            foreach (['errorpages.view', 'errorpages.manage'] as $key) {
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
        DB::table('role_permissions')->whereIn('permission_key', ['errorpages.view', 'errorpages.manage'])->delete();
        DB::table('permissions')->whereIn('key', ['errorpages.view', 'errorpages.manage'])->delete();
    }
};
