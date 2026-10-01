<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Panel user manager (cPanel's "User Manager" / WHM's "Manage Wheel Group Users"
 * equivalent). Hosting ACCOUNTS arrive in Step 3 — these are panel logins.
 */
class UsersController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::query()->with('role')->orderBy('username')->get(),
            'roles' => Role::query()->orderBy('level')->get(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', ['roles' => Role::query()->orderBy('level')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username'  => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9][a-z0-9_.-]*$/', 'unique:users,username'],
            'email'     => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'full_name' => ['nullable', 'string', 'max:190'],
            'role_id'   => ['required', 'exists:roles,id'],
            'password'  => ['required', Password::defaults()],
        ]);

        $role = Role::query()->findOrFail($data['role_id']);

        // Only root may create another root.
        if ($role->isRoot() && ! $request->user()->isRoot()) {
            abort(403, 'Sirf root admin naya root user bana sakta hai.');
        }

        $user = User::query()->create([
            'username'              => strtolower($data['username']),
            'email'                 => $data['email'] ?? null,
            'full_name'             => $data['full_name'] ?? null,
            'role_id'               => $role->id,
            'password_hash'         => Hash::make($data['password']),
            'status'                => 'active',
            'force_password_change' => true, // temp password must be changed on first login
            'created_by'            => $request->user()->id,
        ]);

        Audit::log('user.created', 'warning', 'user', $user->id, [
            'username' => $user->username, 'role' => $role->name,
        ]);

        return redirect()->route('users.index')
            ->with('success', "User '{$user->username}' ban gaya — pehle login par password badalna padega.");
    }

    public function edit(User $user): View
    {
        return view('users.edit', [
            'user'  => $user,
            'roles' => Role::query()->orderBy('level')->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'email'     => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'full_name' => ['nullable', 'string', 'max:190'],
            'role_id'   => ['required', 'exists:roles,id'],
            'status'    => ['required', Rule::in(['active', 'suspended', 'locked'])],
        ]);

        $role = Role::query()->findOrFail($data['role_id']);

        // Guard rails: no self-demotion, no touching the last root, only root can grant root.
        if ($user->id === $request->user()->id && $user->role_id !== $role->id) {
            return back()->withErrors(['role_id' => 'Apna hi role badalna allowed nahi (lock-out se bachne ke liye).']);
        }
        if ($role->isRoot() && ! $request->user()->isRoot()) {
            abort(403, 'Sirf root admin root role de sakta hai.');
        }
        if ($user->isRoot() && ! $role->isRoot() && User::query()->whereHas('role', fn ($q) => $q->where('level', 1))->count() <= 1) {
            return back()->withErrors(['role_id' => 'Ye aakhri root user hai — iska role nahi badal sakte.']);
        }

        $before = ['role' => $user->role?->name, 'status' => $user->status];

        $user->update([
            'email'     => $data['email'] ?? null,
            'full_name' => $data['full_name'] ?? null,
            'role_id'   => $role->id,
            'status'    => $data['status'],
        ]);

        Audit::log('user.updated', 'warning', 'user', $user->id, [
            'before' => $before, 'after' => ['role' => $role->name, 'status' => $data['status']],
        ]);

        return redirect()->route('users.index')->with('success', "User '{$user->username}' update ho gaya.");
    }

    /** Admin-side password reset: sets a new temporary password + forces change. */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', Password::defaults()]]);

        $user->forceFill([
            'password_hash'         => Hash::make($data['password']),
            'force_password_change' => true,
            'failed_logins'         => 0,
            'locked_until'          => null,
            'two_factor_enabled'    => false,
            'two_factor_secret'     => null,
        ])->save();

        Audit::log('user.password_reset', 'critical', 'user', $user->id, ['by_admin' => $request->user()->username]);

        return redirect()->route('users.edit', $user)->with('success', 'New temporary password set ho gaya (2FA bhi reset ho gaya).');
    }
}
