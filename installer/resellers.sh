#!/usr/bin/env bash
# AlphaCP — Reseller Center (WHM-style) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP Reseller Center installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/resellers"
cat > "$PANEL/app/Http/Controllers/ResellersController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WHM "Reseller Center" — resellers ko promote/demote karo aur unke
 * privileges (ACL) manage karo. Role-based RBAC par based (PermissionCatalog).
 */
final class ResellersController extends Controller
{
    public function index(): View
    {
        $resellerRole = Role::query()->where('name', 'reseller')->first();

        $resellers = User::query()
            ->with('role')
            ->when($resellerRole, fn ($q) => $q->where('role_id', $resellerRole->id), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('username')
            ->get();

        $candidates = User::query()
            ->with('role')
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['user', 'mail']))
            ->orderBy('username')
            ->get();

        $catalog = [];
        foreach (PermissionCatalog::all() as $key => $meta) {
            $catalog[$meta['module']][] = ['key' => $key, 'label' => $meta['label']];
        }

        return view('resellers.index', [
            'resellers'  => $resellers,
            'candidates' => $candidates,
            'catalog'    => $catalog,
            'current'    => $resellerRole
                ? $resellerRole->permissions()->pluck('permission_key')->all()
                : PermissionCatalog::defaultsForRole('reseller'),
        ]);
    }

    /** Kisi panel-user ko reseller banao. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::query()->with('role')->findOrFail($data['user_id']);

        if (! in_array($user->role?->name, ['user', 'mail'], true)) {
            return redirect('/resellers')->withErrors(['user_id' => 'Sirf user/mail role ko reseller banaya ja sakta hai.']);
        }

        $role = Role::query()->where('name', 'reseller')->firstOrFail();
        $user->update(['role_id' => $role->id]);

        return redirect('/resellers');
    }

    /** Reseller ko wapas user banao. */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->role?->name !== 'reseller') {
            return redirect('/resellers');
        }

        $role = Role::query()->where('name', 'user')->firstOrFail();
        $user->update(['role_id' => $role->id]);

        return redirect('/resellers');
    }

    /** Reseller role ke privileges (ACL) sync karo. */
    public function updatePrivileges(Request $request): RedirectResponse
    {
        $keys  = $request->input('permissions', []);
        $keys  = is_array($keys) ? $keys : [];
        $valid = array_values(array_intersect($keys, array_keys(PermissionCatalog::all())));
        $valid[] = 'core.access'; // hamesha

        $role = Role::query()->where('name', 'reseller')->firstOrFail();
        $role->permissions()->delete();

        foreach (array_unique($valid) as $key) {
            RolePermission::query()->create(['role_id' => $role->id, 'permission_key' => $key]);
        }

        return redirect('/resellers');
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/ResellersController.php"
cat > "$PANEL/resources/views/resellers/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Reseller Center')
@section('subtitle', 'AlphaCP Reseller Center — resellers promote/demote + privileges (ACL)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Reseller banao</h3>
    @if ($candidates->isEmpty())
        <p class="muted">Koi user/mail-role panel user nahi jo promote ho sake.</p>
    @else
        <form method="POST" action="{{ route('resellers.store') }}">
            @csrf
            <label>Panel user
                <select name="user_id" required>
                    @foreach ($candidates as $c)
                        <option value="{{ $c->id }}">{{ $c->username }} ({{ $c->role?->name }})</option>
                    @endforeach
                </select>
            </label>
            <button class="btn" type="submit">Reseller banao</button>
        </form>
    @endif
</div>

<div class="card">
    <h3>Mere resellers ({{ $resellers->count() }})</h3>
    @if ($resellers->isEmpty())
        <p class="muted">Abhi koi reseller nahi.</p>
    @else
        <table>
            <tr><th>Username</th><th>Email</th><th>Bana</th><th></th></tr>
            @foreach ($resellers as $r)
            <tr>
                <td>{{ $r->username }}</td>
                <td>{{ $r->email ?? '—' }}</td>
                <td>{{ $r->updated_at?->format('d M Y') }}</td>
                <td>
                    <form method="POST" action="{{ route('resellers.destroy', $r) }}" onsubmit="return confirm('Demote karein?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Demote</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>

<div class="card">
    <h3>Reseller privileges (ACL)</h3>
    <p class="muted">Jo checkboxes on hain, reseller role ko wo permissions milti hain.</p>
    <form method="POST" action="{{ route('resellers.privileges') }}">
        @csrf
        @foreach ($catalog as $module => $perms)
            <h4>{{ ucfirst($module) }}</h4>
            <div style="display:flex;flex-wrap:wrap;gap:12px">
                @foreach ($perms as $p)
                    <label style="display:flex;gap:6px;align-items:center">
                        <input type="checkbox" name="permissions[]" value="{{ $p['key'] }}"
                            @checked(in_array($p['key'], $current, true))>
                        {{ $p['label'] }}
                    </label>
                @endforeach
            </div>
        @endforeach
        <button class="btn" type="submit">Privileges save karo</button>
    </form>
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/resellers/index.blade.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "Reseller Center (WHM-style)" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'

// ---- Reseller Center (WHM-style) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/resellers', [\App\Http\Controllers\ResellersController::class, 'index'])
        ->middleware('perm:users.view')->name('resellers.index');
    Route::post('/resellers', [\App\Http\Controllers\ResellersController::class, 'store'])
        ->middleware('perm:roles.manage')->name('resellers.store');
    Route::post('/resellers/privileges', [\App\Http\Controllers\ResellersController::class, 'updatePrivileges'])
        ->middleware('perm:roles.manage')->name('resellers.privileges');
    Route::delete('/resellers/{user}', [\App\Http\Controllers\ResellersController::class, 'destroy'])
        ->middleware('perm:roles.manage')->name('resellers.destroy');
});
// ---- /Reseller Center ----
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
echo " ==> RESELLER CENTER v1.0 INSTALLED  (panel: /resellers)"
echo "=================================================="
alphacp-sync || true
