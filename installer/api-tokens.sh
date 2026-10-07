#!/usr/bin/env bash
# AlphaCP — Manage API Tokens (panel UI, cPanel jaisa) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP API Tokens UI installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/api-tokens"
cat > "$PANEL/app/Models/ApiToken.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bearer API token for the WHM-compatible API (billing integration). */
final class ApiToken extends Model
{
    protected $table = 'api_tokens';

    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
ACP_FILE_EOF
echo "  + app/Models/ApiToken.php"
cat > "$PANEL/app/Http/Controllers/ApiTokensController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** cPanel "Manage API Tokens" — panel UI se Bearer token generate/revoke (billing ke liye). */
final class ApiTokensController extends Controller
{
    public function index(Request $request): View
    {
        $tokens = ApiToken::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return view('api-tokens.index', [
            'tokens'   => $tokens,
            'newToken' => session('new_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $plain = 'acp_' . Str::random(40);

        ApiToken::query()->create([
            'user_id'    => $request->user()->id,
            'name'       => $data['name'],
            'token_hash' => hash('sha256', $plain),
        ]);

        return redirect()->route('api-tokens.index')
            ->with('new_token', $plain)
            ->with('success', 'Token ban gaya — abhi copy karo, dobara NAHI dikhega.');
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        if ((int) $apiToken->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $apiToken->delete();

        return redirect()->route('api-tokens.index')->with('success', 'Token revoke ho gaya.');
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/ApiTokensController.php"
cat > "$PANEL/resources/views/api-tokens/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'API Tokens')
@section('subtitle', 'Manage API Tokens — apni billing software ke liye Bearer token (cPanel jaisa)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($newToken)
<div class="card" style="border:2px solid #2a2">
    <h3>Naya token (EK baar — abhi copy karo)</h3>
    <p><code style="word-break:break-all">{{ $newToken }}</code></p>
    <p class="muted">Ise apne billing software (WHMCS/Blesta/Clientexec) me <em>Authorization: Bearer</em> ke roop me daalo.</p>
</div>
@endif

<div class="card">
    <h3>Generate token</h3>
    <form method="POST" action="{{ route('api-tokens.store') }}">
        @csrf
        <label>Token name
            <input type="text" name="name" placeholder="billing" maxlength="60" required>
        </label>
        <button class="btn" type="submit">Generate</button>
    </form>
</div>

<div class="card">
    <h3>Mere tokens ({{ $tokens->count() }})</h3>
    @if ($tokens->isEmpty())
        <p class="muted">Koi token nahi.</p>
    @else
        <table>
            <tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr>
            @foreach ($tokens as $t)
            <tr>
                <td>{{ $t->name }}</td>
                <td>{{ $t->created_at?->format('d M Y') }}</td>
                <td>{{ $t->last_used_at?->format('d M Y H:i') ?? 'kabhi nahi' }}</td>
                <td>
                    <form method="POST" action="{{ route('api-tokens.destroy', $t) }}" style="display:inline" onsubmit="return confirm('Revoke?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Revoke</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/api-tokens/index.blade.php"
cat > "$PANEL/database/migrations/2026_10_07_000003_create_api_tokens_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000003_create_api_tokens_table.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "Manage API Tokens (panel UI)" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'
// ---- Manage API Tokens (panel UI) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'index'])
        ->middleware('perm:api.view')->name('api-tokens.index');
    Route::post('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'store'])
        ->middleware('perm:api.manage')->name('api-tokens.store');
    Route::delete('/api-tokens/{apiToken}', [\App\Http\Controllers\ApiTokensController::class, 'destroy'])
        ->middleware('perm:api.manage')->name('api-tokens.destroy');
});
// ---- /API Tokens ----
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
echo " ==> API TOKENS UI v1.0 INSTALLED  (panel: /api-tokens)"
echo "=================================================="
alphacp-sync || true
