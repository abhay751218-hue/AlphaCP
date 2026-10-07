#!/usr/bin/env bash
# AlphaCP — Demo/test panel accounts installer  v1.0
# Usage:  sudo bash demo-accounts-v1.0.sh ['Password123'] ['customer1.test']
set -euo pipefail
PANEL=/usr/local/alphacp/panel
PASS="${1:-}"
DOMAIN="${2:-customer1.test}"
echo "=================================================="
echo " AlphaCP Demo Accounts installer  v1.0"
echo "=================================================="
echo "== Step 1: command file =="
mkdir -p "$PANEL/app/Console/Commands"
cat > "$PANEL/app/Console/Commands/DemoAccountsCommand.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountIdentity;
use App\Support\Audit;
use App\Support\DomainProvisioner;
use App\Support\Panel;
use App\Support\PasswordGenerator;
use App\Support\PhpVersions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Test/demo accounts — ek hi command me teeno persona ban jaate hain, taaki
 * panel ke har hisse (Server Manager / Reseller / Account Panel) ko login
 * karke test kiya ja sake.
 *
 *     php artisan alphacp:demo-accounts                       # password generate
 *     php artisan alphacp:demo-accounts --password='Demo@12345'
 *
 * Idempotent: jo account pehle se hai use chheda nahi jaata (password tabhi
 * badalta hai jab --reset-password diya jaaye).
 */
final class DemoAccountsCommand extends Command
{
    protected $signature = 'alphacp:demo-accounts
                            {--password= : teeno demo accounts ka same password (omitted = strong password generate)}
                            {--domain=customer1.test : demo hosting account ka main domain}
                            {--force-change : first login par password change force karo}
                            {--reset-password : existing demo accounts ka password bhi set karo}';

    protected $description = 'Demo/test panel accounts banao (reseller + hosting customer + email-only)';

    /** @var array<int, array{username: string, role: string, name: string, account: bool}> */
    private const PERSONAS = [
        ['username' => 'demoresel', 'role' => 'reseller', 'name' => 'Demo Reseller',   'account' => false],
        ['username' => 'democust',  'role' => 'user',     'name' => 'Demo Customer',   'account' => true],
        ['username' => 'demomail',  'role' => 'mail',     'name' => 'Demo Mail User',  'account' => false],
    ];

    public function handle(): int
    {
        $this->ensureRoles();

        $plain = (string) ($this->option('password') ?: PasswordGenerator::generate(20));

        if ($this->option('password') !== null) {
            $validator = Validator::make(
                ['password' => $plain],
                ['password' => ['required', 'string', Password::defaults()]],
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $line) {
                    $this->error($line);
                }

                return self::FAILURE;
            }
        }

        $package = $this->ensurePackage();
        $domain  = strtolower((string) $this->option('domain'));
        $hash    = Hash::make($plain);
        $rows    = [];

        foreach (self::PERSONAS as $persona) {
            $role = Role::query()->where('name', $persona['role'])->firstOrFail();

            $user = User::query()->where('username', $persona['username'])->first();

            if ($user === null) {
                $user = User::query()->create([
                    'username'              => $persona['username'],
                    'email'                 => $persona['username'] . '@alphacp.local',
                    'full_name'             => $persona['name'],
                    'role_id'               => $role->id,
                    'password_hash'         => $hash,
                    'status'                => 'active',
                    'force_password_change' => (bool) $this->option('force-change'),
                ]);
                $made = 'created';
            } else {
                if ((bool) $this->option('reset-password')) {
                    $user->forceFill([
                        'password_hash'         => $hash,
                        'status'                => 'active',
                        'failed_logins'         => 0,
                        'locked_until'          => null,
                        'force_password_change' => (bool) $this->option('force-change'),
                    ])->save();
                    $made = 'password reset';
                } else {
                    $made = 'already there';
                }
            }

            $accountNote = '—';
            if ($persona['account']) {
                $accountNote = $this->ensureAccount($user, $package, $domain, $plain);
            }

            $rows[] = [
                $persona['role'],
                $user->username,
                in_array($made, ['created', 'password reset'], true) ? $plain : '(unchanged)',
                $accountNote,
                $made,
            ];
        }

        Audit::log('demo.accounts', 'warning', 'user', null, [
            'personas' => array_column(self::PERSONAS, 'username'),
        ]);

        $this->newLine();
        $this->table(['Role', 'Username', 'Password', 'Hosting account', 'Status'], $rows);

        $this->newLine();
        $this->info('Login: https://<server-ip>:8090/login  (owner ke chune doosre ports par bhi wahi panel khulta hai)');
        $this->line('Hosted domain: ' . $domain . ' (demo account ka main domain)');

        return self::SUCCESS;
    }

    /** Roles seed ho chuke hain? Nahi to seeder chala do (fresh install rescue). */
    private function ensureRoles(): void
    {
        $missing = false;
        foreach (['root', 'reseller', 'user', 'mail'] as $name) {
            if (! Role::query()->where('name', $name)->exists()) {
                $missing = true;
                break;
            }
        }

        if ($missing) {
            $this->warn('Roles missing — RolesAndPermissionsSeeder chala rahe hain.');
            $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        }
    }

    private function ensurePackage(): Package
    {
        $package = Package::query()->where('status', 'active')->orderBy('id')->first();

        if ($package !== null) {
            return $package;
        }

        return Package::query()->create([
            'name'        => 'Demo Starter',
            'description' => 'Auto-created by alphacp:demo-accounts',
            'QUOTA'       => 1024,
            'BWLIMIT'     => 10240,
            'status'      => 'active',
        ]);
    }

    /** Hosting account (Account Panel wala) — DB rows only, provisioning agent task nahi. */
    private function ensureAccount(User $owner, Package $package, string $domain, string $plain): string
    {
        $existing = Account::query()->where('username', $owner->username)->first();
        if ($existing !== null) {
            return $existing->main_domain . ' (already)';
        }

        if (! preg_match(AccountIdentity::USERNAME_PATTERN, $owner->username)) {
            return 'skipped (username pattern)';
        }

        $home = rtrim((string) config('acp.paths.accounts', '/home'), '/') . '/' . $owner->username;

        $account = DB::transaction(function () use ($owner, $package, $domain, $home): Account {
            $account = Account::query()->create([
                'server_id'          => Panel::serverId(),
                'package_id'         => $package->id,
                'reseller_id'        => null,
                'owner_user_id'      => $owner->id,
                'username'           => $owner->username,
                'main_domain'        => $domain,
                'contact_email'      => 'demo@' . $domain,
                'home_path'          => $home,
                'php_version'        => PhpVersions::ALLOWED[0],
                'quota_mb'           => $package->quotaMb(),
                'status'             => 'active',
                'setup_completed_at' => now(),
            ]);

            DB::table('account_users')->insert([
                'account_id' => $account->id,
                'user_id'    => $owner->id,
                'role'       => 'owner',
                'created_at' => now(),
            ]);

            DomainProvisioner::seedMain($account);

            return $account;
        });

        $account->recordEvent('account.demo.created', 'Demo account (DB only)');

        return $account->main_domain . ' (created)';
    }
}
ACP_FILE_EOF
echo "  + app/Console/Commands/DemoAccountsCommand.php"

echo "== Step 2: accounts banao (idempotent) =="
cd "$PANEL"
php artisan config:clear || true
if [ -n "$PASS" ]; then
  php artisan alphacp:demo-accounts --password="$PASS" --domain="$DOMAIN"
else
  php artisan alphacp:demo-accounts --domain="$DOMAIN"
fi

echo "== Step 3: login info =="
PORT=8090
ALLPORTS=8090
if [ -f /usr/local/alphacp/var/ports.json ]; then
  P2=$(python3 -c "import json;d=json.load(open('/usr/local/alphacp/var/ports.json'));s=d.get('ssl') or [8090];print(s[0]);" 2>/dev/null || true)
  PA=$(python3 -c "import json;d=json.load(open('/usr/local/alphacp/var/ports.json'));s=d.get('ssl') or [8090];print(' '.join(str(x) for x in s));" 2>/dev/null || true)
  [ -n "${P2:-}" ] && PORT="$P2"
  [ -n "${PA:-}" ] && ALLPORTS="$PA"
fi
IP=$(hostname -I 2>/dev/null | awk '{print $1}' || true)
echo "  Login URL : https://${IP:-<server-ip>}:${PORT}/login   (open ports: ${ALLPORTS})"
echo "  Personas  : demoresel (reseller) · democust (hosting customer) · demomail (email-only)"
echo "  Root admin: pehle se hai (installer wala admin user)"
echo "=================================================="
echo " ==> DEMO ACCOUNTS v1.0 READY"
echo "=================================================="
alphacp-sync || true
