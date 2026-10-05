<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first root admin from installer-provided env values:
 *   ACP_ADMIN_USER, ACP_ADMIN_PASSWORD, ACP_ADMIN_EMAIL
 * Idempotent: never overwrites an existing admin.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Values come from config (see config/acp.php) so they survive config:cache.
        $username = (string) config('acp.admin.user', 'admin');
        $password = (string) config('acp.admin.password', '');
        $email    = (string) (config('acp.admin.email') ?? '');

        if ($password === '') {
            $this->command?->warn('ACP_ADMIN_PASSWORD empty — admin user skipped.');
            return;
        }

        if (User::query()->where('username', $username)->exists()) {
            $this->command?->info("Admin '{$username}' already exists — skipping.");
            return;
        }

        $root = Role::query()->where('name', 'root')->firstOrFail();

        User::query()->create([
            'username'              => $username,
            'email'                 => $email !== '' ? $email : null,
            'full_name'             => 'Server Administrator',
            'role_id'               => $root->id,
            'password_hash'         => Hash::make($password),
            'status'                => 'active',
            'force_password_change' => (bool) config('acp.admin.force_change', true),
        ]);

        $this->command?->info("Root admin '{$username}' created.");
    }
}
