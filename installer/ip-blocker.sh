#!/usr/bin/env bash
# AlphaCP — IP Blocker (cPanel) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP IP Blocker installer  v1.0 (ufw/iptables)"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/ip-blocker"
cat > "$PANEL/app/Support/Firewall.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * cPanel IP Blocker backend — ufw/iptables deny rules.
 * System calls via Process facade so tests can Process::fake() them.
 */
final class Firewall
{
    public static function block(string $ip): void
    {
        Process::run(['ufw', 'deny', 'from', $ip]);
    }

    public static function unblock(string $ip): void
    {
        Process::run(['ufw', 'delete', 'deny', 'from', $ip]);
    }
}
ACP_FILE_EOF
echo "  + app/Support/Firewall.php"
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
cat > "$PANEL/app/Models/BlockedIp.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** cPanel IP Blocker — an IP denied for this hosting account. */
final class BlockedIp extends Model
{
    protected $table = 'blocked_ips';

    protected $fillable = ['account_id', 'ip', 'note'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
ACP_FILE_EOF
echo "  + app/Models/BlockedIp.php"
cat > "$PANEL/app/Http/Controllers/IpBlockerController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BlockedIp;
use App\Support\Audit;
use App\Support\Firewall;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel IP Blocker — account-level IP deny (ufw/iptables). */
final class IpBlockerController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $rows    = $account !== null
            ? BlockedIp::query()->where('account_id', $account->id)->orderBy('ip')->get()
            : collect();

        return view('ip-blocker.index', [
            'account'   => $account,
            'rows'      => $rows,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'ip'   => ['required', 'ip'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        if (BlockedIp::query()->where('account_id', $account->id)->where('ip', $data['ip'])->exists()) {
            return back()->withErrors(['ip' => 'Ye IP pehle se blocked hai.'])->withInput();
        }

        BlockedIp::query()->create([
            'account_id' => $account->id,
            'ip'         => $data['ip'],
            'note'       => (string) ($data['note'] ?? ''),
        ]);

        Firewall::block($data['ip']);
        Audit::log('ipblocker.add', 'info', 'account', $account->id, ['ip' => $data['ip']]);

        return redirect()->route('ip-blocker.index')->with('success', 'IP blocked.');
    }

    public function destroy(Request $request, BlockedIp $blockedIp): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ((int) $blockedIp->account_id !== (int) $account->id) {
            abort(404);
        }

        Firewall::unblock($blockedIp->ip);
        $blockedIp->delete();
        Audit::log('ipblocker.del', 'info', 'account', $account->id, ['ip' => $blockedIp->ip]);

        return redirect()->route('ip-blocker.index')->with('success', 'IP unblocked.');
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
echo "  + app/Http/Controllers/IpBlockerController.php"
cat > "$PANEL/database/migrations/2026_10_07_000002_create_blocked_ips_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_ips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000002_create_blocked_ips_table.php"
cat > "$PANEL/resources/views/ip-blocker/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'IP Blocker')
@section('subtitle', 'Account ke liye IPs deny karo (ufw/iptables) — cPanel IP Blocker jaisa')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Block an IP</h3>
    <form method="POST" action="{{ route('ip-blocker.store') }}">
        @csrf
        <label>IP address
            <input type="text" name="ip" placeholder="203.0.113.7" required>
        </label>
        <label>Note (optional)
            <input type="text" name="note" placeholder="brute-force" maxlength="190">
        </label>
        <button class="btn" type="submit">Block</button>
    </form>
</div>

<div class="card">
    <h3>Blocked IPs ({{ $rows->count() }})</h3>
    @if ($rows->isEmpty())
        <p class="muted">Koi IP blocked nahi hai.</p>
    @else
        <table>
            <tr><th>IP</th><th>Note</th><th></th></tr>
            @foreach ($rows as $row)
            <tr>
                <td><code>{{ $row->ip }}</code></td>
                <td>{{ $row->note }}</td>
                <td>
                    <form method="POST" action="{{ route('ip-blocker.destroy', $row) }}" style="display:inline" onsubmit="return confirm('Unblock?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Unblock</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/ip-blocker/index.blade.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "IP Blocker (portable feature" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'
// ---- IP Blocker (portable feature: ufw/iptables deny) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'index'])
        ->middleware('perm:security.view')->name('ip-blocker.index');
    Route::post('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'store'])
        ->middleware('perm:security.view')->name('ip-blocker.store');
    Route::delete('/ip-blocker/{blockedIp}', [\App\Http\Controllers\IpBlockerController::class, 'destroy'])
        ->middleware('perm:security.view')->name('ip-blocker.destroy');
});
// ---- /IP Blocker ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: migrate + roles re-seed (security.view -> user) =="
cd "$PANEL"
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] migrated + seeded"

echo "=================================================="
echo " ==> IP BLOCKER v1.0 INSTALLED  (panel: /ip-blocker)"
echo "=================================================="
alphacp-sync || true
