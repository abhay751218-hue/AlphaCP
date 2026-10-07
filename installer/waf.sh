#!/usr/bin/env bash
# AlphaCP — Security Tools (ModSecurity WAF + ClamAV) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP Security Tools installer  v1.0 (WAF + ClamAV)"
echo "=================================================="
echo "== Step 1: clamav + modsecurity ensure (fresh VPS ready) =="
if ! command -v clamscan >/dev/null 2>&1; then apt-get update -y >/dev/null && apt-get install -y clamav >/dev/null && echo "[OK] clamav installed"; else echo "[OK] clamav present"; fi
if ! dpkg -s libapache2-mod-security2 >/dev/null 2>&1; then apt-get install -y libapache2-mod-security2 >/dev/null && echo "[OK] modsecurity installed"; else echo "[OK] modsecurity present"; fi

echo "== Step 2: panel feature files =="
mkdir -p "$PANEL/resources/views/security-tools"
cat > "$PANEL/app/Support/Waf.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * cPanel Security suite — ModSecurity (WAF) toggle + ClamAV virus scan.
 * System ops via Process facade so tests can Process::fake() them.
 */
final class Waf
{
    public static function modsecEnabled(): bool
    {
        return Process::run('a2query -m security2')->successful();
    }

    public static function enableModsec(): void
    {
        Process::run('a2enmod security2');
        Process::run('systemctl restart apache2');
    }

    public static function disableModsec(): void
    {
        Process::run('a2dismod security2');
        Process::run('systemctl restart apache2');
    }

    public static function scan(string $path): string
    {
        $result = Process::timeout(120)->run('clamscan -r --quiet ' . escapeshellarg($path));

        return $result->output();
    }
}
ACP_FILE_EOF
echo "  + app/Support/Waf.php"
cat > "$PANEL/app/Support/PermissionCatalog.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Permission keys (RBAC). Modules check these — never hardcode role names.
 *
 * Naming: <module>.<verb>  ·  Root role bypasses all checks (see User::isRoot).
 * Nayi module banate waqt: pehle yahan key add karo, phir step ke migration me
 * role_permissions seed karo.
 */
final class PermissionCatalog
{
    /** @return array<string, array{module:string,label:string,sort:int}> */
    public static function all(): array
    {
        $defs = [
            // module => [ [key, label], ... ]
            'core' => [
                ['core.access', 'Panel access (login)'],
                ['core.self', 'Manage own profile/2FA/password'],
            ],
            'users' => [
                ['users.view', 'View panel users'],
                ['users.manage', 'Create/edit/suspend panel users'],
                ['roles.manage', 'Edit roles & permissions'],
            ],
            'audit' => [
                ['audit.view', 'View audit log'],
            ],
            'server' => [
                ['system.view', 'View server information'],
                ['system.services', 'View service status'],
                ['system.tasks', 'View task queue monitor'],
                ['system.manage', 'Manage server services'],
            ],
            'accounts' => [
                ['accounts.view', 'View hosting accounts'],
                ['accounts.create', 'Create hosting accounts'],
                ['accounts.suspend', 'Suspend/unsuspend accounts'],
                ['accounts.terminate', 'Terminate accounts'],
                ['accounts.modify', 'Modify accounts & passwords'],
            ],
            'packages' => [
                ['packages.view', 'View packages'],
                ['packages.manage', 'Create/edit packages'],
            ],
            'files' => [
                ['files.view', 'Browse files'],
                ['files.manage', 'Upload/edit/delete files'],
            ],
            'privacy' => [
                ['privacy.view', 'View directory privacy'],
                ['privacy.manage', 'Protect folders with Basic Auth'],
            ],
            'ssh' => [
                ['ssh.view', 'View SSH keys'],
                ['ssh.manage', 'Manage SSH keys and shell'],
            ],
            'email' => [
                ['email.view', 'View email accounts'],
                ['email.manage', 'Create/manage email'],
            ],
            'domains' => [
                ['domains.view', 'View domains'],
                ['domains.manage', 'Add/remove domains'],
            ],
            'software' => [
                ['software.view', 'View MultiPHP / software'],
                ['software.manage', 'Change PHP version'],
            ],
            'cron' => [
                ['cron.view', 'View cron jobs'],
                ['cron.manage', 'Create/delete cron jobs'],
            ],
            'errorpages' => [
                ['errorpages.view', 'View error pages'],
                ['errorpages.manage', 'Edit custom error pages'],
            ],
            'indexes' => [
                ['indexes.view', 'View indexes setting'],
                ['indexes.manage', 'Change directory listing'],
            ],
            'mime' => [
                ['mime.view', 'View MIME types'],
                ['mime.manage', 'Add/remove MIME types'],
            ],
            'handlers' => [
                ['handlers.view', 'View Apache handlers'],
                ['handlers.manage', 'Add/remove Apache handlers'],
            ],
            'databases' => [
                ['databases.view', 'View databases'],
                ['databases.manage', 'Create/manage databases'],
            ],
            'dns' => [
                ['dns.view', 'View DNS zones'],
                ['dns.manage', 'Edit DNS records'],
            ],
            'backup' => [
                ['backup.view', 'View backups'],
                ['backup.manage', 'Run/restore backups'],
            ],
            'monitoring' => [
                ['metrics.view', 'View stats & metrics'],
            ],
            'ssl' => [
                ['ssl.view', 'View SSL status'],
                ['ssl.manage', 'Issue/remove SSL certificates'],
            ],
            'security' => [
                ['security.view', 'View security center'],
                ['security.manage', 'Change security settings'],
            ],
            'api' => [
                ['api.view', 'View API tokens'],
                ['api.manage', 'Create/revoke API tokens'],
            ],
            'license' => [
                ['license.view', 'View license status'],
                ['license.manage', 'Activate/update license'],
            ],
            'updates' => [
                ['updates.view', 'View update status'],
                ['updates.manage', 'Run panel updates/rollback'],
            ],
        ];

        $out = [];
        $sort = 10;
        foreach ($defs as $module => $rows) {
            foreach ($rows as [$key, $label]) {
                $out[$key] = ['module' => $module, 'label' => $label, 'sort' => $sort];
                $sort += 10;
            }
        }
        return $out;
    }

    /** Default permission set per role. Root gets everything (see User::isRoot). */
    public static function defaultsForRole(string $role): array
    {
        return match ($role) {
            'root' => array_keys(self::all()),

            'reseller' => [
                'core.access', 'core.self', 'users.view', 'users.manage',
                'accounts.view', 'accounts.create', 'accounts.suspend',
                'accounts.modify', 'packages.view', 'files.view', 'files.manage',
                'email.view', 'email.manage', 'domains.view', 'domains.manage',
                'databases.view', 'databases.manage', 'dns.view', 'dns.manage', 'backup.view',
                'metrics.view', 'security.view', 'audit.view', 'system.view',
                'software.view', 'software.manage', 'cron.view', 'cron.manage',
                'ssl.view', 'ssl.manage', 'errorpages.view', 'errorpages.manage',
                'indexes.view', 'indexes.manage', 'mime.view', 'mime.manage',
                'handlers.view', 'handlers.manage', 'privacy.view', 'privacy.manage',
                'ssh.view', 'ssh.manage',
            ],

            'user' => [
                'core.access', 'core.self', 'files.view', 'files.manage',
                'email.view', 'email.manage', 'domains.view', 'domains.manage',
                'databases.view', 'databases.manage', 'dns.view', 'dns.manage', 'backup.view', 'metrics.view', 'security.view',
                'software.view', 'software.manage', 'cron.view', 'cron.manage',
                'ssl.view', 'ssl.manage', 'errorpages.view', 'errorpages.manage',
                'indexes.view', 'indexes.manage', 'mime.view', 'mime.manage',
                'handlers.view', 'handlers.manage', 'privacy.view', 'privacy.manage',
                'ssh.view', 'ssh.manage',
            ],

            'mail' => [
                'core.access', 'core.self', 'email.view', 'email.manage',
            ],

            default => ['core.access'],
        };
    }
}
ACP_FILE_EOF
echo "  + app/Support/PermissionCatalog.php"
cat > "$PANEL/app/Http/Controllers/SecurityToolsController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\Waf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Security suite — ModSecurity (WAF) toggle + Virus Scanner (ClamAV). */
final class SecurityToolsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('security-tools.index', [
            'account'    => $account,
            'modsec'     => Waf::modsecEnabled(),
            'panelMode'  => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function toggleModsec(Request $request): RedirectResponse
    {
        $this->requireAccount($request);

        if (Waf::modsecEnabled()) {
            Waf::disableModsec();
            Audit::log('waf.modsec_off', 'warning', 'system', null);

            return redirect()->route('security-tools.index')->with('success', 'ModSecurity (WAF) band ho gaya.');
        }

        Waf::enableModsec();
        Audit::log('waf.modsec_on', 'warning', 'system', null);

        return redirect()->route('security-tools.index')->with('success', 'ModSecurity (WAF) chalu ho gaya.');
    }

    public function scan(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);

        $output = Waf::scan(rtrim($account->home_path, '/') . '/public_html');
        Audit::log('waf.scan', 'info', 'account', $account->id);

        return redirect()->route('security-tools.index')
            ->with('success', 'Virus scan complete.')
            ->with('scan_output', $output);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/SecurityToolsController.php"
cat > "$PANEL/resources/views/security-tools/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Security Tools')
@section('subtitle', 'ModSecurity (WAF) + Virus Scanner (ClamAV) — cPanel Security jaisa')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>ModSecurity (WAF)</h3>
    <p>Status: <strong>{{ $modsec ? 'ENABLED' : 'DISABLED' }}</strong></p>
    <form method="POST" action="{{ route('security-tools.modsec') }}">
        @csrf
        <button class="btn" type="submit">{{ $modsec ? 'Disable WAF' : 'Enable WAF' }}</button>
    </form>
</div>

<div class="card">
    <h3>Virus Scanner (ClamAV)</h3>
    <p class="muted">Account ke <code>public_html</code> par scan chalata hai.</p>
    <form method="POST" action="{{ route('security-tools.scan') }}">
        @csrf
        <button class="btn" type="submit">Scan now</button>
    </form>
    @if (session('scan_output'))
        <pre class="muted">{{ session('scan_output') }}</pre>
    @endif
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/security-tools/index.blade.php"

echo "== Step 3: routes (idempotent) =="
if ! grep -q "Security Tools (ModSecurity WAF" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'
// ---- Security Tools (ModSecurity WAF + Virus Scanner) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/security-tools', [\App\Http\Controllers\SecurityToolsController::class, 'index'])
        ->middleware('perm:security.view')->name('security-tools.index');
    Route::post('/security-tools/modsec', [\App\Http\Controllers\SecurityToolsController::class, 'toggleModsec'])
        ->middleware('perm:security.view')->name('security-tools.modsec');
    Route::post('/security-tools/scan', [\App\Http\Controllers\SecurityToolsController::class, 'scan'])
        ->middleware('perm:security.view')->name('security-tools.scan');
});
// ---- /Security Tools ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 4: re-seed + cache clear =="
cd "$PANEL"
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] seeded + cleared"

echo "=================================================="
echo " ==> SECURITY TOOLS v1.0 INSTALLED  (panel: /security-tools)"
echo "=================================================="
alphacp-sync || true
