<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Step 5 — cron_jobs + grant MultiPHP/cron perms to customer role. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            Schema::create('cron_jobs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('minute', 40);
                $table->string('hour', 40);
                $table->string('day', 40);
                $table->string('month', 40);
                $table->string('weekday', 40);
                $table->string('command', 500);
                $table->boolean('enabled')->default(true);
                $table->string('status', 20)->default('pending');
                $table->timestamps();
                $table->index('account_id');
            });
        }

        $sort = 900;
        foreach ([
            'software.view' => ['software', 'View MultiPHP / software'],
            'software.manage' => ['software', 'Change PHP version'],
            'cron.view' => ['cron', 'View cron jobs'],
            'cron.manage' => ['cron', 'Create/delete cron jobs'],
        ] as $key => [$module, $label]) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert([
                    'key' => $key, 'module' => $module, 'label' => $label, 'sort' => $sort,
                ]);
            }
            $sort += 10;
        }

        $this->grant('user', ['software.view', 'software.manage', 'cron.view', 'cron.manage']);
        $this->grant('reseller', ['software.view', 'software.manage', 'cron.view', 'cron.manage']);
    }

    /** @param list<string> $keys */
    private function grant(string $roleName, array $keys): void
    {
        $roleId = DB::table('roles')->where('name', $roleName)->value('id');
        if (! $roleId) {
            return;
        }
        foreach ($keys as $key) {
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

    public function down(): void
    {
        Schema::dropIfExists('cron_jobs');
    }
};
