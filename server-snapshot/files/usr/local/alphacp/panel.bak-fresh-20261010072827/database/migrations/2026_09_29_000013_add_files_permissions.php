<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Step 6 — ensure File Manager perms on customer + reseller (catalog already had them). */
return new class extends Migration
{
    public function up(): void
    {
        $sort = 1050;
        foreach ([
            'files.view' => ['files', 'Browse files'],
            'files.manage' => ['files', 'Upload/edit/delete files'],
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
            foreach (['files.view', 'files.manage'] as $key) {
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
        // Do not drop files.* — they existed before this module went live.
    }
};
