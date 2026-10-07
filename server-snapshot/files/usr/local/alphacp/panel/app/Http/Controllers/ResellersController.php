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
