<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Step 5 — domains (main/addon/sub/parked/redirect) + customer domains.manage. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('domains')) {
            Schema::create('domains', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('type', 20);
                $table->string('domain', 190);
                $table->string('document_root', 255);
                $table->string('redirect_url', 500)->nullable();
                $table->unsignedSmallInteger('redirect_code')->nullable();
                $table->string('php_version', 8)->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamps();
                $table->unique('domain', 'uq_domains_fqdn');
                $table->index('account_id');
                $table->index('type');
            });
        }

        if (Schema::hasTable('accounts')) {
            $now = now();
            foreach (DB::table('accounts')->whereNull('deleted_at')->cursor() as $account) {
                $exists = DB::table('domains')->where('domain', $account->main_domain)->exists();
                if ($exists) {
                    continue;
                }
                DB::table('domains')->insert([
                    'account_id' => $account->id,
                    'type' => 'main',
                    'domain' => $account->main_domain,
                    'document_root' => rtrim((string) $account->home_path, '/') . '/public_html',
                    'php_version' => $account->php_version,
                    'status' => $account->status === 'active' ? 'active' : 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $userRole = DB::table('roles')->where('name', 'user')->value('id');
        if ($userRole) {
            $has = DB::table('role_permissions')
                ->where('role_id', $userRole)
                ->where('permission_key', 'domains.manage')
                ->exists();
            if (! $has) {
                DB::table('role_permissions')->insert([
                    'role_id' => $userRole,
                    'permission_key' => 'domains.manage',
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
