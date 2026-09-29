@extends('layouts.panel')

@section('title', 'User Manager')
@section('subtitle', 'Panel logins — hosting accounts alag page par hain (Accounts)')

@section('actions')
    @can('users.manage')
        <a class="btn small" href="{{ route('users.create') }}">+ Naya user</a>
    @endcan
@endsection

@section('content')
<div class="card">
    <div class="table-wrap">
        <table>
            <tr><th>Username</th><th>Naam</th><th>Role</th><th>Status</th><th>2FA</th><th>Last login</th><th></th></tr>
            @forelse ($users as $user)
                <tr>
                    <td class="mono">{{ $user->username }}</td>
                    <td>{{ $user->full_name ?? '—' }}</td>
                    <td><span class="badge {{ $user->isRoot() ? 'red' : 'blue' }}">{{ $user->role?->name }}</span></td>
                    <td><span class="badge {{ $user->status === 'active' ? 'green' : 'amber' }}">{{ $user->status }}</span></td>
                    <td>{!! $user->two_factor_enabled ? '<span class="badge green">ON</span>' : '<span class="badge">OFF</span>' !!}</td>
                    <td class="muted">{{ $user->last_login_at?->diffForHumans() ?? 'kabhi nahi' }}</td>
                    <td class="right">
                        @can('users.manage')
                            <a class="btn small ghost" href="{{ route('users.edit', $user) }}">edit</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Koi user nahi.</td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card mt">
    <h3>🎭 Roles (RBAC)</h3>
    <div class="table-wrap">
        <table>
            <tr><th>Role</th><th>Level</th><th>Kis ke liye</th><th>Users</th></tr>
            @foreach ($roles as $role)
                <tr>
                    <td class="mono">{{ $role->name }}</td>
                    <td>{{ $role->level }}</td>
                    <td class="muted">{{ $role->description }}</td>
                    <td class="muted">{{ $role->users()->count() }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    <p class="help mt">Root = sab kuch. Reseller = apne customers. User = apna account. Mail = sirf email.
        Permission keys <span class="mono">app/Support/PermissionCatalog.php</span> me define hote hain.</p>
</div>
@endsection
