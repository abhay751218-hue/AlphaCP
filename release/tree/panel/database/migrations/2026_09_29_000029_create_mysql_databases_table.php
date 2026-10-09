<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Step 8 — customer MySQL database names (prefix username_). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mysql_databases')) {
            Schema::create('mysql_databases', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('name', 16);
                $table->timestamps();
                $table->unique(['account_id', 'name']);
                $table->index('account_id');
            });
        }

        if (! DB::table('permissions')->where('key', 'databases.manage')->exists()) {
            DB::table('permissions')->insert([
                'key' => 'databases.manage',
                'module' => 'databases',
                'label' => 'Create/manage databases',
                'sort' => 2000,
            ]);
        }

        $roleId = DB::table('roles')->where('name', 'user')->value('id');
        if ($roleId) {
            $has = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_key', 'databases.manage')
                ->exists();
            if (! $has) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_key' => 'databases.manage',
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mysql_databases');
        $roleId = DB::table('roles')->where('name', 'user')->value('id');
        if ($roleId) {
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_key', 'databases.manage')
                ->delete();
        }
    }
};
