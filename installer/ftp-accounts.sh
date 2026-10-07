#!/usr/bin/env bash
# AlphaCP — FTP Accounts (Pure-FTPd) portable installer  v1.0
# Abhi wale + naye dedicated/VPS server dono par chalega (idempotent).
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP FTP Accounts installer  v1.0 (pure-ftpd)"
echo "=================================================="

echo "== Step 1: pure-ftpd ensure =="
if ! command -v pure-pw >/dev/null 2>&1; then
  echo "[..] pure-ftpd missing -> apt install"
  apt-get update -y >/dev/null && apt-get install -y pure-ftpd pure-ftpd-common >/dev/null
  echo "[OK] pure-ftpd installed"
else
  echo "[OK] pure-ftpd present ($(command -v pure-pw))"
fi

echo "== Step 2: PureDB + chroot config =="
mkdir -p /etc/pure-ftpd/conf
echo yes > /etc/pure-ftpd/conf/ChrootEveryone
echo /etc/pure-ftpd/pureftpd.pdb > /etc/pure-ftpd/conf/PureDB
echo 1000 > /etc/pure-ftpd/conf/MinUID
touch /etc/pure-ftpd/pureftpd.passwd
pure-pw mkdb || true
(systemctl enable pure-ftpd >/dev/null 2>&1 && systemctl restart pure-ftpd) || service pure-ftpd restart || true
echo "[OK] pure-ftpd configured + started"

echo "== Step 3: panel feature files =="
mkdir -p "$PANEL/resources/views/ftp"
cat > "$PANEL/app/Support/Ftp.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * cPanel-style FTP Accounts backed by Pure-FTPd (pure-pw / PureDB).
 *
 * System calls are made through Laravel's Process facade so tests can
 * Process::fake() them. On the server, the portable installer ensures
 * pure-ftpd + pure-pw exist and the PureDB is wired in.
 */
final class Ftp
{
    /** pure-ftpd binary path if present. */
    public static function binary(): ?string
    {
        foreach (['/usr/sbin/pure-ftpd', '/usr/bin/pure-ftpd'] as $b) {
            if (is_file($b)) {
                return $b;
            }
        }

        return null;
    }

    public static function enabled(): bool
    {
        return self::binary() !== null;
    }

    /** Create a Pure-FTPd virtual user (chroot to its home). */
    public static function addUser(string $user, string $password, string $home, int $uid = 1000, int $gid = 1000): void
    {
        Process::input($password . "\n" . $password . "\n")
            ->run(['pure-pw', 'useradd', $user, '-u', (string) $uid, '-g', (string) $gid, '-d', $home, '-m']);
    }

    public static function delUser(string $user): void
    {
        Process::run(['pure-pw', 'userdel', $user, '-m']);
    }

    public static function passwd(string $user, string $password): void
    {
        Process::input($password . "\n" . $password . "\n")
            ->run(['pure-pw', 'passwd', $user, '-m']);
    }
}
ACP_FILE_EOF
echo "  + app/Support/Ftp.php"
cat > "$PANEL/app/Models/FtpAccount.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** cPanel-style FTP account (Pure-FTPd virtual user) owned by a hosting Account. */
final class FtpAccount extends Model
{
    protected $table = 'ftp_accounts';

    protected $fillable = ['account_id', 'username', 'home_path', 'quota_mb', 'status'];

    protected $casts = ['quota_mb' => 'integer'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
ACP_FILE_EOF
echo "  + app/Models/FtpAccount.php"
cat > "$PANEL/app/Http/Controllers/FtpController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FtpAccount;
use App\Support\Audit;
use App\Support\Ftp;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel FTP Accounts — Pure-FTPd virtual users, one chroot home per FTP login. */
final class FtpController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $rows    = $account !== null
            ? FtpAccount::query()->where('account_id', $account->id)->orderBy('username')->get()
            : collect();

        return view('ftp.index', [
            'account'   => $account,
            'rows'      => $rows,
            'enabled'   => Ftp::enabled(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['username' => 'Cannot manage FTP on a suspended/terminated account.']);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-z][a-z0-9]{0,15}$/'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'quota_mb' => ['nullable', 'integer', 'min:0', 'max:102400'],
        ]);

        $login = strtolower($account->username) . '_' . strtolower($data['username']);
        if (FtpAccount::query()->where('username', $login)->exists()) {
            return back()->withErrors(['username' => 'FTP login already exists.'])->withInput();
        }

        $home = rtrim($account->home_path, '/') . '/ftp/' . strtolower($data['username']);

        FtpAccount::query()->create([
            'account_id' => $account->id,
            'username'   => $login,
            'home_path'  => $home,
            'quota_mb'   => (int) ($data['quota_mb'] ?? 0),
            'status'     => 'active',
        ]);

        Ftp::addUser($login, $data['password'], $home);
        Audit::log('ftp.add', 'info', 'account', $account->id, ['user' => $login]);

        return redirect()->route('ftp.index')->with('success', 'FTP account created.');
    }

    public function password(Request $request, FtpAccount $ftpAccount): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ((int) $ftpAccount->account_id !== (int) $account->id) {
            abort(404);
        }

        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:72']]);

        Ftp::passwd($ftpAccount->username, $data['password']);
        Audit::log('ftp.passwd', 'info', 'account', $account->id, ['user' => $ftpAccount->username]);

        return redirect()->route('ftp.index')->with('success', 'FTP password changed.');
    }

    public function destroy(Request $request, FtpAccount $ftpAccount): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ((int) $ftpAccount->account_id !== (int) $account->id) {
            abort(404);
        }

        Ftp::delUser($ftpAccount->username);
        $ftpAccount->delete();
        Audit::log('ftp.del', 'info', 'account', $account->id, ['user' => $ftpAccount->username]);

        return redirect()->route('ftp.index')->with('success', 'FTP account removed.');
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
echo "  + app/Http/Controllers/FtpController.php"
cat > "$PANEL/database/migrations/2026_10_07_000001_create_ftp_accounts_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ftp_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('username')->unique();
            $table->string('home_path');
            $table->unsignedInteger('quota_mb')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ftp_accounts');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000001_create_ftp_accounts_table.php"
cat > "$PANEL/resources/views/ftp/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'FTP Accounts')
@section('subtitle', 'Pure-FTPd virtual users — ek chroot home per FTP login')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if (! $enabled)
<div class="card">
    <p><strong>FTP daemon not installed.</strong> Run the portable installer
    (<code>installer/ftp-accounts.sh</code>) to enable Pure-FTPd on this server.</p>
</div>
@endif

<div class="card">
    <h3>Add FTP Account</h3>
    <form method="POST" action="{{ route('ftp.store') }}">
        @csrf
        <label>FTP login suffix
            <input type="text" name="username" placeholder="deploys" pattern="[a-z][a-z0-9]{0,15}" required>
        </label>
        <label>Password
            <input type="password" name="password" minlength="8" required>
        </label>
        <label>Quota (MB, 0 = unlimited)
            <input type="number" name="quota_mb" min="0" max="102400" value="0">
        </label>
        @if ($account)
        <p class="muted">Full login: <code>{{ $account->username }}_suffix</code> · home: <code>{{ $account->home_path }}/ftp/suffix</code></p>
        @endif
        <button class="btn" type="submit">Create</button>
    </form>
</div>

<div class="card">
    <h3>Existing FTP Accounts ({{ $rows->count() }})</h3>
    @if ($rows->isEmpty())
        <p class="muted">No FTP accounts yet.</p>
    @else
        <table>
            <tr><th>Login</th><th>Home</th><th>Quota</th><th>Status</th><th></th></tr>
            @foreach ($rows as $row)
            <tr>
                <td><code>{{ $row->username }}</code></td>
                <td><code>{{ $row->home_path }}</code></td>
                <td>{{ $row->quota_mb > 0 ? $row->quota_mb . ' MB' : 'unlimited' }}</td>
                <td>{{ $row->status }}</td>
                <td>
                    <form method="POST" action="{{ route('ftp.destroy', $row) }}" style="display:inline" onsubmit="return confirm('Delete FTP account?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/ftp/index.blade.php"

echo "== Step 4: routes (idempotent) =="
if ! grep -q "FTP Accounts (portable feature" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'
// ---- FTP Accounts (portable feature: pure-ftpd) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ftp', [\App\Http\Controllers\FtpController::class, 'index'])
        ->middleware('perm:files.view')->name('ftp.index');
    Route::post('/ftp', [\App\Http\Controllers\FtpController::class, 'store'])
        ->middleware('perm:files.manage')->name('ftp.store');
    Route::post('/ftp/{ftpAccount}/password', [\App\Http\Controllers\FtpController::class, 'password'])
        ->middleware('perm:files.manage')->name('ftp.password');
    Route::delete('/ftp/{ftpAccount}', [\App\Http\Controllers\FtpController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('ftp.destroy');
});
// ---- /FTP ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 5: migrate + cache clear =="
cd "$PANEL"
php artisan migrate --force
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] migrated"

echo "=================================================="
echo " ==> FTP ACCOUNTS v1.0 INSTALLED  (panel: /ftp)"
echo "=================================================="
alphacp-sync || true
