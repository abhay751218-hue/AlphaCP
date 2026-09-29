<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Create (or reset) a panel admin — used by the installer and by sysadmins.
 *
 *   php artisan alphacp:create-admin --username=admin --password=... [--email=...] [--role=superadmin]
 */
final class CreateAdmin extends Command
{
    protected $signature = 'alphacp:create-admin
        {--username=admin : Panel username}
        {--password=     : Password (omit to auto-generate and print)}
        {--email=        : Email (defaults to username@localhost)}
        {--role=superadmin : Role slug}';

    protected $description = 'Create or update an AlphaCP panel user (bootstrap admin)';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        $password = (string) $this->option('password');
        $email    = (string) ($this->option('email') ?: $username . '@localhost');
        $roleSlug = (string) $this->option('role');

        $generated = false;
        if ($password === '') {
            $password  = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'AZ'), '=');
            $generated = true;
        }

        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
        if ($roleId === null) {
            $this->error("role '{$roleSlug}' nahi mila — pehle migration chalao.");

            return self::FAILURE;
        }

        /** @var User|null $user */
        $user = User::where('username', $username)->first();

        if ($user === null) {
            $user = new User();
            $user->username = $username;
            $user->email    = $email;
        }

        $user->password     = $password;      // hashed by the model cast
        $user->display_name = $user->display_name ?: ucfirst($username);
        $user->role_id      = (int) $roleId;
        $user->status       = 'active';
        $user->save();

        Audit::log('panel.user.created', actorType: 'system', targetType: 'user', targetId: (int) $user->id,
            severity: 'warning', meta: ['username' => $username, 'role' => $roleSlug, 'generated' => $generated],
            ip: '127.0.0.1');

        $this->info("panel user ready: {$username} (role {$roleSlug}, id {$user->id})");
        if ($generated) {
            $this->line('generated password: ' . $password);
        }

        return self::SUCCESS;
    }
}
