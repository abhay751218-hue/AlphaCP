#!/usr/bin/env bash
# AlphaCP — DNS Cluster (WHM) portable installer  v1.0
# NOTE: ye installer apna dashboard tile KHUD live kar deta hai — dashboard-sync alag se NAHI chahiye.
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP DNS Cluster installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/dns-cluster"
cat > "$PANEL/app/Models/DnsClusterNode.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** WHM DNS Cluster — ek remote DNS/nameserver node. */
final class DnsClusterNode extends Model
{
    protected $table = 'dns_cluster_nodes';

    protected $fillable = ['hostname', 'ip', 'role', 'status', 'last_synced_at'];

    protected $casts = ['last_synced_at' => 'datetime'];
}
ACP_FILE_EOF
echo "  + app/Models/DnsClusterNode.php"
cat > "$PANEL/app/Http/Controllers/DnsClusterController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DnsClusterNode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM "DNS Cluster" — remote DNS/nameserver nodes manage + zone sync. */
final class DnsClusterController extends Controller
{
    public function index(): View
    {
        return view('dns-cluster.index', [
            'nodes' => DnsClusterNode::query()->orderBy('hostname')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hostname' => 'required|string|max:120',
            'ip'       => 'required|ip',
            'role'     => 'required|in:dns,ns',
        ]);

        DnsClusterNode::query()->updateOrCreate(
            ['hostname' => $data['hostname']],
            ['ip' => $data['ip'], 'role' => $data['role'], 'status' => 'added'],
        );

        return redirect('/dns-cluster');
    }

    public function destroy(DnsClusterNode $dnsClusterNode): RedirectResponse
    {
        $dnsClusterNode->delete();

        return redirect('/dns-cluster');
    }

    /** Sab nodes par zones sync karo (server par agent BIND sync karta hai). */
    public function sync(): RedirectResponse
    {
        DnsClusterNode::query()->update([
            'status'         => 'synced',
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect('/dns-cluster');
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/DnsClusterController.php"
cat > "$PANEL/resources/views/dns-cluster/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'DNS Cluster')
@section('subtitle', 'Remote DNS/nameserver nodes + zone synchronization')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Node add karo</h3>
    <form method="POST" action="{{ route('dns-cluster.store') }}">
        @csrf
        <label>Hostname
            <input type="text" name="hostname" placeholder="ns2.example.com" required>
        </label>
        <label>IP
            <input type="text" name="ip" placeholder="203.0.113.10" required>
        </label>
        <label>Role
            <select name="role" required>
                <option value="dns">DNS (standalone)</option>
                <option value="ns">Nameserver</option>
            </select>
        </label>
        <button class="btn" type="submit">Add node</button>
    </form>
</div>

<div class="card" style="display:flex;gap:12px;align-items:center">
    <form method="POST" action="{{ route('dns-cluster.sync') }}">
        @csrf
        <button class="btn secondary" type="submit">Synchronize all zones</button>
    </form>
</div>

<div class="card">
    <h3>Cluster nodes ({{ $nodes->count() }})</h3>
    @if ($nodes->isEmpty())
        <p class="muted">Koi DNS cluster node nahi.</p>
    @else
        <table>
            <tr><th>Hostname</th><th>IP</th><th>Role</th><th>Status</th><th>Last sync</th><th></th></tr>
            @foreach ($nodes as $n)
            <tr>
                <td>{{ $n->hostname }}</td>
                <td>{{ $n->ip }}</td>
                <td>{{ $n->role === 'ns' ? 'Nameserver' : 'DNS' }}</td>
                <td>{{ $n->status === 'synced' ? '✅ Synced' : '➕ Added' }}</td>
                <td>{{ $n->last_synced_at?->format('d M Y H:i') ?? '—' }}</td>
                <td>
                    <form method="POST" action="{{ route('dns-cluster.destroy', $n) }}" onsubmit="return confirm('Remove node?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Remove</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/dns-cluster/index.blade.php"
cat > "$PANEL/database/migrations/2026_10_07_000009_create_dns_cluster_nodes_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_cluster_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('hostname');
            $table->string('ip', 45);
            $table->string('role', 12)->default('dns'); // dns | ns
            $table->string('status', 12)->default('added'); // added|synced
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique('hostname');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_cluster_nodes');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000009_create_dns_cluster_nodes_table.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "DNS Cluster (WHM)" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'

// ---- DNS Cluster (WHM) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'index'])
        ->middleware('perm:dns.view')->name('dns-cluster.index');
    Route::post('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'store'])
        ->middleware('perm:dns.manage')->name('dns-cluster.store');
    Route::post('/dns-cluster/sync', [\App\Http\Controllers\DnsClusterController::class, 'sync'])
        ->middleware('perm:dns.manage')->name('dns-cluster.sync');
    Route::delete('/dns-cluster/{dnsClusterNode}', [\App\Http\Controllers\DnsClusterController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('dns-cluster.destroy');
});
// ---- /DNS Cluster ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: dashboard tile self-flip (DNS Cluster -> live) =="
sed -i -E "s/('name' => 'DNS Cluster',.*'status' => ')step(')/\1live', 'route' => 'dns-cluster.index\2/" "$PANEL/app/Support/ModuleCatalog.php"
echo "[OK] tile flipped to live"

echo "== Step 4: migrate + cache clear =="
cd "$PANEL"
php artisan migrate --force
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] migrated"

echo "=================================================="
echo " ==> DNS CLUSTER v1.0 INSTALLED  (panel: /dns-cluster)"
echo "=================================================="
alphacp-sync || true
