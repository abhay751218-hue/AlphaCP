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

use RuntimeException;

/**
 * License signing — do algorithms:
 *   * ed25519 (preferred): customer panel PUBLIC key se offline verify karta
 *     hai; secret sirf issuer (aapke license server) ke paas.
 *   * hmac-sha256 (fallback): hosts jahan sodium extension nahi — aise
 *     customer panels offline verify nahi kar sakte, activation online hoti
 *     hai aur stored record trust hota hai (root-owned file).
 *
 * Purana issue()/verify() (HMAC key-string format) backward-compat ke liye
 * bana hua hai; naya flow issueSigned()/canonical() use karta hai.
 */
final class LicenseSigner
{
    public static function hasSodium(): bool
    {
        return function_exists('sodium_crypto_sign_keypair')
            && function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Payload ko sign karo. Canonical (sorted-keys) JSON par signature hota
     * hai — customer panel ka canonicalPayload se byte-exact match.
     *
     * @param array<string, mixed> $payload
     * @return array{signature: string, algo: string}
     */
    public static function issueSigned(array $payload, string $hmacSecret): array
    {
        $message = self::canonical($payload);

        if (self::hasSodium()) {
            $kp  = self::keypair();
            $sig = sodium_crypto_sign_detached($message, base64_decode($kp['secret'], true) ?: '');

            return ['signature' => base64_encode($sig), 'algo' => 'ed25519'];
        }

        return [
            'signature' => base64_encode(hash_hmac('sha256', $message, $hmacSecret, true)),
            'algo'      => 'hmac',
        ];
    }

    /** Ed25519 public key PEM (raw 32-byte base64 body) — customer panel ke liye. */
    public static function publicPem(): ?string
    {
        if (! self::hasSodium()) {
            return null;
        }

        $body = base64_decode(self::keypair()['public'], true);

        if ($body === false || $body === '') {
            return null;
        }

        $b64 = base64_encode($body);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($b64, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** @return array{secret: string, public: string} */
    public static function keypair(): array
    {
        $path = storage_path('app/private/license_signer.json');

        if (is_file($path)) {
            $kp = json_decode((string) @file_get_contents($path), true);
            if (is_array($kp) && isset($kp['secret'], $kp['public'])) {
                /** @var array{secret: string, public: string} $kp */
                return $kp;
            }
        }

        if (! self::hasSodium()) {
            throw new RuntimeException('sodium unavailable — ed25519 keypair nahi ban sakta.');
        }

        $pair = sodium_crypto_sign_keypair();
        $kp   = [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];

        @mkdir(dirname($path), 0700, true);
        @file_put_contents($path, json_encode($kp), LOCK_EX);
        @chmod($path, 0600);

        return $kp;
    }

    /**
     * Canonical JSON — panel ke LicenseClient::canonicalPayload jaisa hi:
     * recursive key-sort, lists order preserve, UNESCAPED_SLASHES|UNICODE.
     *
     * @param array<string, mixed> $value
     */
    public static function canonical(array $value): string
    {
        return json_encode(
            self::sortObject($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<mixed, mixed> $value @return array<mixed, mixed> */
    private static function sortObject(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                static fn (mixed $item): mixed => is_array($item) ? self::sortObject($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortObject($item);
            }
        }

        return $value;
    }

    // ---- legacy (HMAC key-string) — purane issued keys ke liye ----

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

    protected $fillable = [
        'server_id', 'plan', 'license_uid', 'expires_at', 'key_hash',
        'payload', 'signature', 'sig_algo', 'revoked',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked'    => 'boolean',
        'payload'    => 'array',
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
 * Sellable License Server — plan-based signed keys.
 *
 * Owner (aap) yahan se customer licenses nikalte ho:
 *   starter=10 accounts · pro=50 · business=200 · owner=UNLIMITED+lifetime
 * Customer panel /api/v1/activate se payload+signature lekar offline store
 * karta hai (Ed25519 verify; sodium na ho to hmac fallback = online-verified).
 */
final class LicenseServerController extends Controller
{
    /** Plan → account cap (-1 = unlimited). */
    public const PLAN_CAPS = ['starter' => 10, 'pro' => 50, 'business' => 200, 'owner' => -1];

    private function secret(): string
    {
        return (string) (config('acp.license_secret') ?: config('app.key'));
    }

    public function index(): View
    {
        return view('license-server.index', [
            'keys'     => LicenseKey::query()->orderByDesc('id')->get(),
            'newKey'   => session('new_key'),
            'caps'     => self::PLAN_CAPS,
            'publicPem' => LicenseSigner::publicPem(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'server_id' => 'required|string|max:120',
            'plan'      => 'required|string|in:starter,pro,business,owner',
            'days'      => 'required|integer|min:0|max:36500',
        ]);

        $plan = (string) $data['plan'];
        $days = (int) $data['days'];

        if ($days === 0 && $plan !== 'owner') {
            return back()->withErrors(['days' => 'Lifetime (0 din) sirf owner plan ke liye hai.'])->withInput();
        }

        if ($days > 3650 && $plan !== 'owner') {
            return back()->withErrors(['days' => 'Customer plans zyada se zyada 3650 din.'])->withInput();
        }

        $payload = [
            'license_uid'  => 'ACP-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12)),
            'product'      => 'alphacp',
            'tier'         => $plan,
            'features'     => ['core'],
            'max_accounts' => self::PLAN_CAPS[$plan],
            'max_servers'  => $plan === 'owner' ? 99 : 1,
            'issued_at'    => gmdate(DATE_ATOM),
            'expires_at'   => $days === 0 ? null : gmdate(DATE_ATOM, time() + $days * 86400),
            'grace_days'   => 7,
            'bindings'     => ['fingerprint'],
        ];

        $signed = LicenseSigner::issueSigned($payload, $this->secret());

        LicenseKey::query()->create([
            'server_id'  => $data['server_id'],
            'plan'       => $plan,
            'license_uid' => $payload['license_uid'],
            'expires_at' => $days === 0 ? null : date('Y-m-d H:i:s', time() + $days * 86400),
            'key_hash'   => hash('sha256', $payload['license_uid']),
            'payload'    => $payload,
            'signature'  => $signed['signature'],
            'sig_algo'   => $signed['algo'],
            'revoked'    => false,
        ]);

        return redirect('/license-server')->with('new_key', $payload['license_uid']);
    }

    public function destroy(LicenseKey $licenseKey): RedirectResponse
    {
        $licenseKey->update(['revoked' => true]);

        return redirect('/license-server');
    }

    /**
     * Customer panel ka activation endpoint (public).
     * Panel yahan se payload+signature lekar signed record store karta hai.
     */
    public function activate(Request $request): JsonResponse
    {
        $key = trim((string) $request->input('license_key', ''));

        $record = LicenseKey::query()->where('key_hash', hash('sha256', $key))->first();

        if ($key === '' || $record === null || $record->revoked) {
            return response()->json(['message' => 'License key invalid ya revoked hai.'], 422);
        }

        return response()->json([
            'payload'   => $record->payload,
            'signature' => (string) $record->signature,
        ]);
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
                <option value="starter">starter (10 accounts)</option>
                <option value="pro">pro (50 accounts)</option>
                <option value="business">business (200 accounts)</option>
                <option value="owner">owner (UNLIMITED + lifetime)</option>
            </select>
        </label>
        <label>Din (validity; 0 = lifetime, sirf owner)
            <input type="number" name="days" value="365" min="0" max="36500" required>
        </label>
        <button class="btn" type="submit">Issue license</button>
    </form>
</div>

@if ($publicPem)
<div class="card">
    <h3>Public key (customer panel ke .env me ACP_LICENSE_PUBLIC_KEY)</h3>
    <pre style="word-break:break-all;white-space:pre-wrap">{{ $publicPem }}</pre>
    <p class="muted">Customer apne panel me ye key daalega — offline Ed25519 verify ke liye.</p>
</div>
@else
<div class="card">
    <p class="muted">Sodium extension is host par nahi — licenses online-verified mode me chalenge (hmac).</p>
</div>
@endif

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
cat > "$PANEL/database/migrations/2026_10_07_000011_add_payload_to_license_keys.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('license_keys', 'license_uid')) {
                $table->string('license_uid', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('license_keys', 'payload')) {
                $table->json('payload')->nullable();
            }
            if (! Schema::hasColumn('license_keys', 'signature')) {
                $table->text('signature')->nullable();
            }
            if (! Schema::hasColumn('license_keys', 'sig_algo')) {
                $table->string('sig_algo', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('license_keys', function (Blueprint $table): void {
            foreach (['license_uid', 'payload', 'signature', 'sig_algo'] as $column) {
                if (Schema::hasColumn('license_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000011_add_payload_to_license_keys.php"

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
// Customer panel ka activation (public) — payload+signature signed record ke liye
Route::post('/api/v1/activate', [\App\Http\Controllers\LicenseServerController::class, 'activate'])
    ->name('license-server.activate');
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
