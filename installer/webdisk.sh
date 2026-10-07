#!/usr/bin/env bash
# AlphaCP — Web Disk (WebDAV accounts) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP Web Disk installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/webdisk"
cat > "$PANEL/app/Models/WebDiskAccount.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** cPanel Web Disk account (WebDAV) — read-only ya read-write. */
final class WebDiskAccount extends Model
{
    protected $table = 'webdisk_accounts';

    protected $fillable = ['user_id', 'login', 'permissions'];
}
ACP_FILE_EOF
echo "  + app/Models/WebDiskAccount.php"
cat > "$PANEL/app/Http/Controllers/WebDiskController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WebDiskAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel "Web Disk" — WebDAV accounts (read-only / read-write). */
final class WebDiskController extends Controller
{
    public function index(Request $request): View
    {
        return view('webdisk.index', [
            'accounts' => WebDiskAccount::query()
                ->where('user_id', $request->user()->id)
                ->orderBy('login')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login'       => 'required|string|regex:/^[a-z0-9._-]+$/i|max:60',
            'permissions' => 'required|in:ro,rw',
        ]);

        WebDiskAccount::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'login' => $data['login']],
            ['permissions' => $data['permissions']],
        );

        return redirect('/webdisk');
    }

    public function destroy(WebDiskAccount $webDiskAccount, Request $request): RedirectResponse
    {
        if ($webDiskAccount->user_id === $request->user()->id) {
            $webDiskAccount->delete();
        }

        return redirect('/webdisk');
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/WebDiskController.php"
cat > "$PANEL/resources/views/webdisk/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Web Disk')
@section('subtitle', 'WebDAV accounts — files ko desktop se access karo (read-only / read-write)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Naya Web Disk account</h3>
    <form method="POST" action="{{ route('webdisk.store') }}">
        @csrf
        <label>Login
            <input type="text" name="login" placeholder="designer" pattern="[A-Za-z0-9._-]+" required>
        </label>
        <label>Permissions
            <select name="permissions" required>
                <option value="rw">Read-Write</option>
                <option value="ro">Read-Only</option>
            </select>
        </label>
        <button class="btn" type="submit">Create</button>
    </form>
</div>

<div class="card">
    <h3>Web Disk accounts ({{ $accounts->count() }})</h3>
    @if ($accounts->isEmpty())
        <p class="muted">Koi Web Disk account nahi.</p>
    @else
        <table>
            <tr><th>Login</th><th>Permissions</th><th></th></tr>
            @foreach ($accounts as $a)
            <tr>
                <td>{{ $a->login }}</td>
                <td>{{ $a->permissions === 'rw' ? 'Read-Write' : 'Read-Only' }}</td>
                <td>
                    <form method="POST" action="{{ route('webdisk.destroy', $a) }}" onsubmit="return confirm('Delete?')">
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
echo "  + resources/views/webdisk/index.blade.php"
cat > "$PANEL/database/migrations/2026_10_07_000006_create_webdisk_accounts_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webdisk_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('login');
            $table->string('permissions', 4)->default('rw'); // ro | rw
            $table->timestamps();
            $table->unique(['user_id', 'login']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webdisk_accounts');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000006_create_webdisk_accounts_table.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "Web Disk (WebDAV accounts)" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'

// ---- Web Disk (WebDAV accounts) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'index'])
        ->middleware('perm:files.view')->name('webdisk.index');
    Route::post('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'store'])
        ->middleware('perm:files.manage')->name('webdisk.store');
    Route::delete('/webdisk/{webDiskAccount}', [\App\Http\Controllers\WebDiskController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('webdisk.destroy');
});
// ---- /Web Disk ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: migrate + cache clear =="
cd "$PANEL"
php artisan migrate --force
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] migrated"

echo "=================================================="
echo " ==> WEB DISK v1.0 INSTALLED  (panel: /webdisk)"
echo "=================================================="
alphacp-sync || true
