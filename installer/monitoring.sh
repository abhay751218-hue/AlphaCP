#!/usr/bin/env bash
# AlphaCP — Monitoring / Resource Usage portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP Monitoring installer  v1.0 (resource usage)"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/monitoring"
cat > "$PANEL/app/Support/SystemStats.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * WHM-style server resource stats (disk / memory / load / cpu).
 * Reads /proc + PHP disk functions with safe fallbacks (no crash anywhere).
 */
final class SystemStats
{
    /** @return array{total_mb:int,used_mb:int,pct:int} */
    public static function memory(): array
    {
        $total = 0;
        $avail = 0;
        $lines = @file('/proc/meminfo') ?: [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'MemTotal:')) {
                $total = (int) (preg_replace('/\D/', '', $line) ?: 0);
            }
            if (str_starts_with($line, 'MemAvailable:')) {
                $avail = (int) (preg_replace('/\D/', '', $line) ?: 0);
            }
        }
        $totalMb = intdiv($total, 1024);
        $availMb = intdiv($avail, 1024);
        $usedMb  = max(0, $totalMb - $availMb);
        $pct     = $totalMb > 0 ? (int) round($usedMb * 100 / $totalMb) : 0;

        return ['total_mb' => $totalMb, 'used_mb' => $usedMb, 'pct' => $pct];
    }

    /** @return array{1:float,5:float,15:float} */
    public static function load(): array
    {
        $raw = trim((string) @file_get_contents('/proc/loadavg'));
        $parts = preg_split('/\s+/', $raw) ?: [];

        return [
            1  => (float) ($parts[0] ?? 0),
            5  => (float) ($parts[1] ?? 0),
            15 => (float) ($parts[2] ?? 0),
        ];
    }

    /** @return array{total_gb:int,used_gb:int,pct:int} */
    public static function disk(string $path = '/'): array
    {
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return ['total_gb' => 0, 'used_gb' => 0, 'pct' => 0];
        }
        $used = $total - $free;
        $toGb = static fn (float $b): int => (int) round($b / 1024 ** 3);

        return ['total_gb' => $toGb($total), 'used_gb' => $toGb($used), 'pct' => (int) round($used * 100 / $total)];
    }

    public static function cpus(): int
    {
        $cpu = (string) @file_get_contents('/proc/cpuinfo');
        $n   = substr_count($cpu, 'processor');

        return max(1, $n);
    }
}
ACP_FILE_EOF
echo "  + app/Support/SystemStats.php"
cat > "$PANEL/app/Http/Controllers/MonitoringController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ModuleCatalog;
use App\Support\SystemStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM-style Monitoring / Resource Usage dashboard. */
final class MonitoringController extends Controller
{
    public function index(Request $request): View
    {
        return view('monitoring.index', [
            'memory'    => SystemStats::memory(),
            'load'      => SystemStats::load(),
            'disk'      => SystemStats::disk(),
            'cpus'      => SystemStats::cpus(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/MonitoringController.php"
cat > "$PANEL/resources/views/monitoring/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Resource Usage')
@section('subtitle', 'Server monitoring — disk / memory / load / CPU (WHM jaisa)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="cards">
    <div class="card"><h3>Memory</h3><p class="big">{{ $memory['pct'] }}%</p><p class="muted">{{ $memory['used_mb'] }} / {{ $memory['total_mb'] }} MB</p></div>
    <div class="card"><h3>Disk</h3><p class="big">{{ $disk['pct'] }}%</p><p class="muted">{{ $disk['used_gb'] }} / {{ $disk['total_gb'] }} GB</p></div>
    <div class="card"><h3>Load (1/5/15)</h3><p class="big">{{ $load[1] }}</p><p class="muted">{{ $load[5] }} / {{ $load[15] }}</p></div>
    <div class="card"><h3>CPU cores</h3><p class="big">{{ $cpus }}</p></div>
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/monitoring/index.blade.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "Monitoring / Resource Usage" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'
// ---- Monitoring / Resource Usage ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/monitoring', [\App\Http\Controllers\MonitoringController::class, 'index'])
        ->middleware('perm:metrics.view')->name('monitoring.index');
});
// ---- /Monitoring ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: cache clear =="
cd "$PANEL"
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] caches cleared"

echo "=================================================="
echo " ==> MONITORING v1.0 INSTALLED  (panel: /monitoring)"
echo "=================================================="
alphacp-sync || true
