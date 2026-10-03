<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Step 6 — grant SSH Access perms to customer + reseller. */
return new class extends Migration
{
    public function up(): void
    {
        $sort = 1090;
        foreach ([
            'ssh.view' => ['ssh', 'View SSH keys'],
            'ssh.manage' => ['ssh', 'Manage SSH keys and shell'],
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
            foreach (['ssh.view', 'ssh.manage'] as $key) {
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
        DB::table('role_permissions')->whereIn('permission_key', ['ssh.view', 'ssh.manage'])->delete();
        DB::table('permissions')->whereIn('key', ['ssh.view', 'ssh.manage'])->delete();
    }
};
