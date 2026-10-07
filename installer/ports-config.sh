#!/usr/bin/env bash
# AlphaCP — Owner Ports Config (panel page) installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP Owner Ports Config installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/ports"
cat > "$PANEL/app/Models/PortConfig.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner-defined panel ports (single row). */
final class PortConfig extends Model
{
    protected $table = 'port_configs';

    protected $fillable = ['data'];

    protected $casts = ['data' => 'array'];
}
ACP_FILE_EOF
echo "  + app/Models/PortConfig.php"
cat > "$PANEL/app/Http/Controllers/PortsController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PortConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Owner Ports Config — owner yahan se panel ports choose karta hai.
 * Save par DB + var/ports.json likhta hai; apply-step (installer/agent) ise nginx par lagata hai.
 * 8090 hamesha primary (brand) port hai.
 */
final class PortsController extends Controller
{
    public const PRIMARY = 8090;
    private const CPANEL_SSL  = [2083, 2087, 2096];
    private const CPANEL_HTTP = [2082, 2086, 2095];

    public function index(): View
    {
        return view('ports.index', ['cfg' => $this->current()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cpanel' => 'nullable|boolean',
            'custom' => 'nullable|string',
        ]);

        $cpanel = $request->boolean('cpanel');

        $custom = [];
        foreach (preg_split('/[\s,]+/', (string) ($data['custom'] ?? '')) ?: [] as $p) {
            $n = (int) $p;
            if ($n > 1023 && $n < 65536 && $n !== self::PRIMARY) {
                $custom[] = $n;
            }
        }
        $custom = array_values(array_unique($custom));

        $ssl  = array_values(array_unique(array_merge([self::PRIMARY], $cpanel ? self::CPANEL_SSL : [], $custom)));
        $http = $cpanel ? self::CPANEL_HTTP : [];

        $cfg = ['ssl' => $ssl, 'http' => $http, 'cpanel' => $cpanel, 'custom' => $custom];

        // single row upsert
        $rec = PortConfig::query()->first();
        if ($rec) {
            $rec->update(['data' => $cfg]);
        } else {
            PortConfig::query()->create(['data' => $cfg]);
        }

        try {
            File::put($this->portsFile(), json_encode($cfg, JSON_PRETTY_PRINT));
        } catch (\Throwable) {
            // DB source-of-truth hai.
        }

        return redirect('/ports')->with('success', 'Ports save ho gaye. Ab apply-step chalayen (ya agent auto-apply).');
    }

    /** @return array{ssl:list<int>,http:list<int>,cpanel:bool,custom:list<int>} */
    private function current(): array
    {
        $rec = PortConfig::query()->first();

        return $rec?->data ?? ['ssl' => [self::PRIMARY], 'http' => [], 'cpanel' => false, 'custom' => []];
    }

    private function portsFile(): string
    {
        return (string) (config('acp.ports_file') ?: '/usr/local/alphacp/etc/ports.json');
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/PortsController.php"
cat > "$PANEL/resources/views/ports/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Ports Config')
@section('subtitle', 'Owner control — panel ke ports choose karo (8090 hamesha primary)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if (session('success'))
<div class="card" style="border:2px solid #2a2"><p>{{ session('success') }}</p></div>
@endif

<div class="card">
    <h3>Active ports</h3>
    <p>
        <strong>HTTPS:</strong> {{ implode(', ', $cfg['ssl']) }}<br>
        <strong>HTTP (redirect):</strong> {{ $cfg['http'] ? implode(', ', $cfg['http']) : '—' }}
    </p>
</div>

<div class="card">
    <h3>Ports set karo</h3>
    <form method="POST" action="{{ route('ports.store') }}">
        @csrf
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="cpanel" value="1" @checked($cfg['cpanel'])>
            Compatibility ports on karo (2083/2087/2096 + redirect 2082/2086/2095)
        </label>
        <label>Custom HTTPS ports (space/comma separated, 1024-65535)
            <input type="text" name="custom" value="{{ implode(' ', $cfg['custom']) }}" placeholder="8443 9091">
        </label>
        <button class="btn" type="submit">Save ports</button>
    </form>
    <p class="muted">8090 (primary) hamesha on rehta hai. Save ke baad apply-step chalayen taaki nginx par lage.</p>
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/ports/index.blade.php"
cat > "$PANEL/database/migrations/2026_10_07_000010_create_port_configs_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('port_configs', function (Blueprint $table): void {
            $table->id();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_configs');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000010_create_port_configs_table.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "Owner Ports Config" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'

// ---- Owner Ports Config ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ports', [\App\Http\Controllers\PortsController::class, 'index'])
        ->middleware('perm:system.manage')->name('ports.index');
    Route::post('/ports', [\App\Http\Controllers\PortsController::class, 'store'])
        ->middleware('perm:system.manage')->name('ports.store');
});
// ---- /Ports ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: migrate + cache clear + tile self-flip =="
sed -i -E "s/('name' => 'Service Status',.*'status' => ')live(')/\1live\3/" "$PANEL/app/Support/ModuleCatalog.php" || true
cd "$PANEL"
php artisan migrate --force
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] migrated"

echo "=================================================="
echo " ==> PORTS CONFIG v1.0 INSTALLED  (owner page: /ports)"
echo "=================================================="
alphacp-sync || true
