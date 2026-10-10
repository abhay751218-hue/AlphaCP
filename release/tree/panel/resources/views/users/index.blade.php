@extends('layouts.panel')

@section('title', 'User Manager')
@section('subtitle', 'Panel logins — hosting accounts alag page par hain (Accounts)')

@section('actions')
    @can('users.manage')
        <a class="btn small" href="{{ route('users.create') }}">+ New user</a>
    @endcan
    <a class="btn small secondary" href="{{ route('accounts.index') }}">Accounts</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Panel users</h3>
        <div class="stat"><span class="num">{{ $users->count() }}</span><span class="unit">logins ·
            {{ $users->where('two_factor_enabled', true)->count() }} with 2FA</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) RBAC</h3>
        <p class="help" style="margin:6px 0 0">Root = sab kuch · Reseller = apne customers · User = apna account ·
            Mail = sirf email. Permission keys <span class="mono">PermissionCatalog.php</span> me.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Users</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search users…" data-filter-rows="#acp-users tbody tr" aria-label="Search users">
    </div>
    <div class="table-wrap">
        <table id="acp-users">
            <thead><tr><th>Username</th><th>Naam</th><th>Role</th><th>Status</th><th>2FA</th><th>Last login</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td class="mono">@include('partials.icons', ['icon' => 'user', 'cls' => 'hico']) {{ $user->username }}</td>
                    <td>{{ $user->full_name ?? '—' }}</td>
                    <td><span class="badge {{ $user->isRoot() ? 'red' : 'blue' }}">{{ $user->role?->name }}</span></td>
                    <td><span class="badge {{ $user->status === 'active' ? 'green' : 'amber' }}">{{ $user->status }}</span></td>
                    <td>{!! $user->two_factor_enabled ? '<span class="badge green">ON</span>' : '<span class="badge">OFF</span>' !!}</td>
                    <td class="muted">{{ $user->last_login_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="right">
                        @can('users.manage')
                            <a class="btn small secondary" href="{{ route('users.edit', $user) }}">Edit</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No users yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Roles (RBAC)</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Role</th><th>Level</th><th>Audience</th><th>Users</th></tr></thead>
            <tbody>
            @foreach ($roles as $role)
                <tr>
                    <td><span class="badge {{ $role->name === 'root' ? 'red' : 'blue' }} mono">{{ $role->name }}</span></td>
                    <td class="mono">{{ $role->level }}</td>
                    <td class="muted">{{ $role->description }}</td>
                    <td><span class="badge gray">{{ $role->users()->count() }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
