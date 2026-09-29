<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use App\Support\PasswordGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Reset a panel user's password from the shell.
 *
 * This is the documented rescue path (mirrors WHM → Password Modification):
 *
 *     alphacp:admin-password admin --password='…' --force-change --reset-2fa
 *     alphacp:admin-password admin                 # generate a strong password
 *
 * It always clears lockouts, because a locked-out admin is exactly the case
 * this command exists for.
 */
class AdminPasswordCommand extends Command
{
    protected $signature = 'alphacp:admin-password
                            {username=admin : panel username to reset}
                            {--password= : new password (a strong one is generated when omitted)}
                            {--force-change : require a password change at next login}
                            {--reset-2fa : also disable and clear 2FA for this user}';

    protected $description = 'Reset a panel user password (lockout recovery, installer use)';

    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $user = User::query()->where('username', $username)->first();

        if (! $user) {
            $this->error("User '{$username}' panel me nahi mila.");
            $this->line('Available users: ' . User::query()->pluck('username')->implode(', '));

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: $this->generatePassword());

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        $user->forceFill([
            'password_hash'         => Hash::make($password),
            'force_password_change' => (bool) $this->option('force-change'),
            'failed_logins'         => 0,
            'locked_until'          => null,
        ]);

        if ($this->option('reset-2fa')) {
            $user->forceFill([
                'two_factor_enabled'      => false,
                'two_factor_secret'       => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_step'    => null,
            ]);
        }

        $user->save();

        Audit::log('admin.password_reset', 'warning', 'user', (int) $user->id, [
            'source'    => 'cli',
            'force'     => (bool) $this->option('force-change'),
            'reset_2fa' => (bool) $this->option('reset-2fa'),
        ]);

        $this->newLine();
        $this->info("Password reset: {$user->username}");
        $this->line("  password       : {$password}");
        $this->line('  change on login: ' . ($this->option('force-change') ? 'yes' : 'no'));
        $this->line('  lockout cleared: yes');
        if ($this->option('reset-2fa')) {
            $this->line('  2FA            : disabled (user can set it up again)');
        }
        $this->newLine();

        return self::SUCCESS;
    }

    /** Always satisfies the password policy (see PasswordGenerator for the old ~3% failure). */
    private function generatePassword(): string
    {
        return PasswordGenerator::generate(20);
    }
}
