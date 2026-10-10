<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

/** Creates the 4 built-in roles + the permission catalog + default mappings. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // ---- permissions -----------------------------------------------------
        foreach (PermissionCatalog::all() as $key => $def) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['module' => $def['module'], 'label' => $def['label'], 'sort' => $def['sort']],
            );
        }

        // ---- roles ------------------------------------------------------------
        $roles = [
            ['name' => 'root',     'label' => 'Root Admin',        'level' => 1, 'description' => 'Poora server — sab kuch'],
            ['name' => 'reseller', 'label' => 'Reseller',          'level' => 2, 'description' => 'Apne customers manage karta hai'],
            ['name' => 'user',     'label' => 'Hosting Customer',  'level' => 3, 'description' => 'Apna account (cPanel jaisa)'],
            ['name' => 'mail',     'label' => 'Email Only',        'level' => 4, 'description' => 'Sirf email accounts'],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(
                ['name' => $role['name']],
                [...$role, 'is_system' => true],
            );
        }

        // ---- default role → permission mappings --------------------------------
        foreach (['root', 'reseller', 'user', 'mail'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            foreach (PermissionCatalog::defaultsForRole($roleName) as $key) {
                RolePermission::query()->updateOrCreate(
                    ['role_id' => $role->id, 'permission_key' => $key],
                    [],
                );
            }
            // Root: make sure a newly added permission never gets forgotten.
            if ($role->isRoot()) {
                foreach (array_keys(PermissionCatalog::all()) as $key) {
                    RolePermission::query()->firstOrCreate(['role_id' => $role->id, 'permission_key' => $key]);
                }
            }
        }
    }
}
