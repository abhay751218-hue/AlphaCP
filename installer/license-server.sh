#!/usr/bin/env bash
# AlphaCP — License Server (sellable signed licenses) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP License Server installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/license-server"
cat > "$PANEL/app/Support/LicenseSigner.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Signed license keys (HMAC-SHA256) — sellable, offline-verifiable.
 * Format: base64url(json payload) . '.' . base64url(hmac_sha256(json, secret)).
 * Customer panel locally verify kar sakta hai (secret sirf issuer ke paas).
 */
final class LicenseSigner
{
    public static function issue(array $payload, string $secret): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig  = hash_hmac('sha256', (string) $json, $secret, true);

        return self::b64url((string) $json) . '.' . self::b64url($sig);
    }

    /** @return array|null payload, ya null agar signature/expiry invalid */
    public static function verify(string $key, string $secret): ?array
    {
        $parts = explode('.', $key);
        if (count($parts) !== 2) {
            return null;
        }

        $json = self::b64urlDecode($parts[0]);
        $sig  = self::b64urlDecode($parts[1]);
        if ($json === null || $sig === null) {
            return null;
        }

        $expect = hash_hmac('sha256', $json, $secret, true);
        if (! hash_equals($expect, $sig)) {
            return null;
        }

        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null; // expired
        }

        return $payload;
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): ?string
    {
        $pad  = strlen($s) % 4;
        $s   .= $pad ? str_repeat('=', 4 - $pad) : '';
        $raw  = base64_decode(strtr($s, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
ACP_FILE_EOF
echo "  + app/Support/LicenseSigner.php"
cat > "$PANEL/app/Models/LicenseKey.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Issued (sellable) license key — revocation ke liye record. */
final class LicenseKey extends Model
{
    protected $table = 'license_keys';

    protected $fillable = ['server_id', 'plan', 'expires_at', 'key_hash', 'revoked'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked'    => 'boolean',
    ];
}
ACP_FILE_EOF
echo "  + app/Models/LicenseKey.php"
cat > "$PANEL/app/Http/Controllers/LicenseServerController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LicenseKey;
use App\Support\LicenseSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sellable License Server — signed keys issue/verify/revoke.
 * Owner (aap) yahan se customer licenses nikalte ho; customer panel offline verify karta hai.
 */
final class LicenseServerController extends Controller
{
    private function secret(): string
    {
        return (string) (config('acp.license_secret') ?: config('app.key'));
    }

    public function index(): View
    {
        return view('license-server.index', [
            'keys'    => LicenseKey::query()->orderByDesc('id')->get(),
            'newKey'  => session('new_key'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'server_id' => 'required|string|max:120',
            'plan'      => 'required|string|in:starter,pro,business',
            'days'      => 'required|integer|min:1|max:3650',
        ]);

        $payload = [
            'sub'  => $data['server_id'],
            'plan' => $data['plan'],
            'iat'  => time(),
            'exp'  => time() + ((int) $data['days'] * 86400),
        ];

        $key = LicenseSigner::issue($payload, $this->secret());

        LicenseKey::query()->create([
            'server_id'  => $data['server_id'],
            'plan'       => $data['plan'],
            'expires_at' => date('Y-m-d H:i:s', $payload['exp']),
            'key_hash'   => hash('sha256', $key),
            'revoked'    => false,
        ]);

        return redirect('/license-server')->with('new_key', $key);
    }

    public function destroy(LicenseKey $licenseKey): RedirectResponse
    {
        $licenseKey->update(['revoked' => true]);

        return redirect('/license-server');
    }

    /** Customer panel ka online check (public, read-only). */
    public function verify(Request $request): JsonResponse
    {
        $key     = (string) $request->input('key', '');
        $payload = LicenseSigner::verify($key, $this->secret());

        $record = LicenseKey::query()->where('key_hash', hash('sha256', $key))->first();

        $valid = $payload !== null && ($record === null || ! $record->revoked);

        return response()->json([
            'valid'   => $valid,
            'payload' => $valid ? $payload : null,
            'revoked' => $record?->revoked ?? false,
        ]);
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/LicenseServerController.php"
cat > "$PANEL/resources/views/license-server/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'License Server')
@section('subtitle', 'Sellable signed licenses — customers ke liye keys issue/verify/revoke')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($newKey)
<div class="card" style="border:2px solid #2a2">
    <h3>Naya license key (EK baar — copy karo)</h3>
    <p><code style="word-break:break-all">{{ $newKey }}</code></p>
    <p class="muted">Ye key customer ko do — unka panel ise offline verify karega.</p>
</div>
@endif

<div class="card">
    <h3>License issue karo</h3>
    <form method="POST" action="{{ route('license-server.store') }}">
        @csrf
        <label>Server ID
            <input type="text" name="server_id" placeholder="srv-001 / IP / domain" required>
        </label>
        <label>Plan
            <select name="plan" required>
                <option value="starter">starter</option>
                <option value="pro">pro</option>
                <option value="business">business</option>
            </select>
        </label>
        <label>Din (validity)
            <input type="number" name="days" value="365" min="1" max="3650" required>
        </label>
        <button class="btn" type="submit">Issue license</button>
    </form>
</div>

<div class="card">
    <h3>Issued licenses ({{ $keys->count() }})</h3>
    @if ($keys->isEmpty())
        <p class="muted">Abhi koi license nahi.</p>
    @else
        <table>
            <tr><th>Server</th><th>Plan</th><th>Expires</th><th>Status</th><th></th></tr>
            @foreach ($keys as $k)
            <tr>
                <td>{{ $k->server_id }}</td>
                <td>{{ $k->plan }}</td>
                <td>{{ $k->expires_at?->format('d M Y') }}</td>
                <td>{{ $k->revoked ? 'REVOKED' : 'active' }}</td>
                <td>
                    @unless ($k->revoked)
                    <form method="POST" action="{{ route('license-server.destroy', $k) }}" onsubmit="return confirm('Revoke karein?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Revoke</button>
                    </form>
                    @endunless
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/license-server/index.blade.php"
cat > "$PANEL/database/migrations/2026_10_07_000004_create_license_keys_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('server_id');
            $table->string('plan');
            $table->timestamp('expires_at')->nullable();
            $table->string('key_hash', 64)->unique();
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_keys');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000004_create_license_keys_table.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "License Server (sellable signed licenses)" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'

// ---- License Server (sellable signed licenses) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/license-server', [\App\Http\Controllers\LicenseServerController::class, 'index'])
        ->middleware('perm:license.manage')->name('license-server.index');
    Route::post('/license-server', [\App\Http\Controllers\LicenseServerController::class, 'store'])
        ->middleware('perm:license.manage')->name('license-server.store');
    Route::delete('/license-server/{licenseKey}', [\App\Http\Controllers\LicenseServerController::class, 'destroy'])
        ->middleware('perm:license.manage')->name('license-server.destroy');
});
// Customer panel ka online verify (public, read-only)
Route::post('/license-server/verify', [\App\Http\Controllers\LicenseServerController::class, 'verify'])
    ->name('license-server.verify');
// ---- /License Server ----
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

echo "NOTE: ACP_LICENSE_SECRET .env me set karo (license signing ke liye)."
echo "=================================================="
echo " ==> LICENSE SERVER v1.0 INSTALLED  (panel: /license-server)"
echo "=================================================="
alphacp-sync || true
