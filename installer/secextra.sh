#!/usr/bin/env bash
# AlphaCP — Hotlink + Leech Protection portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP Hotlink + Leech Protection installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/secextra"
cat > "$PANEL/app/Models/SecurityExtra.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-user security add-on settings (hotlink / leech protection). */
final class SecurityExtra extends Model
{
    protected $table = 'security_extras';

    protected $fillable = ['user_id', 'kind', 'data'];

    protected $casts = ['data' => 'array'];
}
ACP_FILE_EOF
echo "  + app/Models/SecurityExtra.php"
cat > "$PANEL/app/Http/Controllers/SecurityExtrasController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SecurityExtra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel Security extras — Hotlink Protection + Leech Protection.
 * Settings per-user store hoti hain (DB), config generation server-side.
 */
final class SecurityExtrasController extends Controller
{
    private const DEFAULTS = [
        'hotlink' => ['enabled' => false, 'allowed' => [], 'allow_direct' => true],
        'leech'   => ['enabled' => false, 'max_logins' => 4, 'action' => 'block'],
    ];

    public function hotlink(Request $request): View
    {
        return view('secextra.hotlink', ['s' => $this->get($request, 'hotlink')]);
    }

    public function leech(Request $request): View
    {
        return view('secextra.leech', ['s' => $this->get($request, 'leech')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $kind = (string) $request->input('kind', '');
        if (! array_key_exists($kind, self::DEFAULTS)) {
            return redirect('/hotlink-protection');
        }

        $data = $kind === 'hotlink'
            ? [
                'enabled'      => $request->boolean('enabled'),
                'allowed'      => array_values(array_filter(array_map('trim', explode("\n", (string) $request->input('allowed', ''))))),
                'allow_direct' => $request->boolean('allow_direct'),
            ]
            : [
                'enabled'    => $request->boolean('enabled'),
                'max_logins' => (int) min(20, max(1, (int) $request->input('max_logins', 4))),
                'action'     => in_array($request->input('action'), ['block', 'redirect'], true) ? $request->input('action') : 'block',
            ];

        SecurityExtra::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'kind' => $kind],
            ['data' => $data],
        );

        return redirect($kind === 'hotlink' ? '/hotlink-protection' : '/leech-protection');
    }

    private function get(Request $request, string $kind): array
    {
        $rec = SecurityExtra::query()
            ->where('user_id', $request->user()->id)
            ->where('kind', $kind)
            ->first();

        return array_merge(self::DEFAULTS[$kind], $rec?->data ?? []);
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/SecurityExtrasController.php"
cat > "$PANEL/resources/views/secextra/hotlink.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Hotlink Protection')
@section('subtitle', 'Apni images/videos ko doosri sites par embed hone se bachao')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Hotlink Protection</h3>
    <form method="POST" action="{{ route('secextra.store') }}">
        @csrf
        <input type="hidden" name="kind" value="hotlink">
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="enabled" value="1" @checked($s['enabled'])> Enabled
        </label>
        <label>Allowed domains (ek per line)
            <textarea name="allowed" rows="4" placeholder="example.com&#10;cdn.example.com">{{ implode("\n", $s['allowed']) }}</textarea>
        </label>
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="allow_direct" value="1" @checked($s['allow_direct'])> Direct URL access allow karo
        </label>
        <button class="btn" type="submit">Save</button>
    </form>
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/secextra/hotlink.blade.php"
cat > "$PANEL/resources/views/secextra/leech.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Leech Protection')
@section('subtitle', 'Password-sharing / brute-force se accounts bachao (login limit)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Leech Protection</h3>
    <form method="POST" action="{{ route('secextra.store') }}">
        @csrf
        <input type="hidden" name="kind" value="leech">
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="enabled" value="1" @checked($s['enabled'])> Enabled
        </label>
        <label>Max logins (24h me, per user)
            <input type="number" name="max_logins" value="{{ $s['max_logins'] }}" min="1" max="20">
        </label>
        <label>Limit cross karne par
            <select name="action">
                <option value="block" @selected($s['action'] === 'block')>Block login</option>
                <option value="redirect" @selected($s['action'] === 'redirect')>Redirect to URL</option>
            </select>
        </label>
        <button class="btn" type="submit">Save</button>
    </form>
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/secextra/leech.blade.php"
cat > "$PANEL/database/migrations/2026_10_07_000005_create_security_extras_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_extras', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20);            // hotlink | leech
            $table->json('data')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_extras');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000005_create_security_extras_table.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "Security extras: Hotlink + Leech Protection" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'

// ---- Security extras: Hotlink + Leech Protection ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/hotlink-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'hotlink'])
        ->middleware('perm:security.view')->name('secextra.hotlink');
    Route::get('/leech-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'leech'])
        ->middleware('perm:security.view')->name('secextra.leech');
    Route::post('/security-extras', [\App\Http\Controllers\SecurityExtrasController::class, 'store'])
        ->middleware('perm:security.manage')->name('secextra.store');
});
// ---- /Security extras ----
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
echo " ==> HOTLINK + LEECH PROTECTION v1.0 INSTALLED"
echo "=================================================="
alphacp-sync || true
