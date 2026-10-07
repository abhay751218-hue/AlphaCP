#!/usr/bin/env bash
# AlphaCP — Metrics (Visitors/Errors/Bandwidth) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP Metrics installer  v1.0 (access-log stats)"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/metrics"
cat > "$PANEL/app/Support/Metrics.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * cPanel-style Metrics (Visitors / Errors / Bandwidth) parsed from an
 * Apache/nginx combined-format access log. No DB needed — computed on read.
 */
final class Metrics
{
    /**
     * Parse a combined-format access log and aggregate stats.
     *
     * @return array{requests:int,bytes:int,visitors:int,errors:int,top:array<string,int>}
     */
    public static function parse(string $logPath): array
    {
        $stats = ['requests' => 0, 'bytes' => 0, 'visitors' => 0, 'errors' => 0, 'top' => []];

        if (! is_file($logPath) || ! is_readable($logPath)) {
            return $stats;
        }

        $ips  = [];
        $top  = [];
        $fh   = fopen($logPath, 'r');
        if ($fh === false) {
            return $stats;
        }

        while (($line = fgets($fh)) !== false) {
            if (! preg_match('/^(\S+) \S+ \S+ \[[^\]]*\] "([A-Z]+) (\S+)[^"]*" (\d{3}) (\d+|-)/', $line, $m)) {
                continue;
            }
            $stats['requests']++;
            $stats['bytes'] += ($m[5] === '-') ? 0 : (int) $m[5];
            $ips[$m[1]] = true;
            if ((int) $m[4] >= 400) {
                $stats['errors']++;
            }
            $path = strtok($m[3], '?');
            $top[$path] = ($top[$path] ?? 0) + 1;
        }
        fclose($fh);

        arsort($top);
        $stats['top']      = array_slice($top, 0, 10, true);
        $stats['visitors'] = count($ips);

        return $stats;
    }

    public static function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1) . ' PB';
    }
}
ACP_FILE_EOF
echo "  + app/Support/Metrics.php"
cat > "$PANEL/app/Http/Controllers/MetricsController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Metrics;
use App\Support\ModuleCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Metrics — Visitors / Errors / Bandwidth from the account access log. */
final class MetricsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $stats   = $account !== null ? Metrics::parse($this->logPathFor($account)) : null;

        return view('metrics.index', [
            'account'   => $account,
            'stats'     => $stats,
            'human'     => $stats !== null ? Metrics::human($stats['bytes']) : '0 B',
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    private function logPathFor(Account $account): string
    {
        $pattern = (string) config('acp.access_log_pattern', '/var/log/apache2/{user}-access.log');

        return str_replace('{user}', $account->username, $pattern);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/MetricsController.php"
cat > "$PANEL/resources/views/metrics/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Metrics')
@section('subtitle', 'Visitors / Errors / Bandwidth — access log se (cPanel Metrics jaisa)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($stats === null)
<div class="card"><p class="muted">Is login ka koi hosting account nahi hai.</p></div>
@else
<div class="cards">
    <div class="card"><h3>Bandwidth</h3><p class="big">{{ $human }}</p></div>
    <div class="card"><h3>Visitors</h3><p class="big">{{ $stats['visitors'] }}</p></div>
    <div class="card"><h3>Requests</h3><p class="big">{{ $stats['requests'] }}</p></div>
    <div class="card"><h3>Errors (4xx/5xx)</h3><p class="big">{{ $stats['errors'] }}</p></div>
</div>

<div class="card">
    <h3>Top Pages</h3>
    @if (empty($stats['top']))
        <p class="muted">Abhi koi traffic nahi.</p>
    @else
        <table>
            <tr><th>Page</th><th>Hits</th></tr>
            @foreach ($stats['top'] as $path => $hits)
            <tr><td><code>{{ $path }}</code></td><td>{{ $hits }}</td></tr>
            @endforeach
        </table>
    @endif
</div>
@endif
@endsection
ACP_FILE_EOF
echo "  + resources/views/metrics/index.blade.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "Metrics (portable feature" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'
// ---- Metrics (portable feature: access-log stats) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/metrics', [\App\Http\Controllers\MetricsController::class, 'index'])
        ->middleware('perm:metrics.view')->name('metrics.index');
});
// ---- /Metrics ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: cache clear =="
cd "$PANEL"
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] caches cleared  (log path: config acp.access_log_pattern, default /var/log/apache2/{user}-access.log)"

echo "=================================================="
echo " ==> METRICS v1.0 INSTALLED  (panel: /metrics)"
echo "=================================================="
alphacp-sync || true
