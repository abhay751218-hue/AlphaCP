@extends('layouts.panel')

@section('title', 'MySQL Databases')
@section('subtitle', 'Real MariaDB databases — create, manage, drop')

@section('actions')
    <a class="btn small secondary" href="{{ route('mysql-users.index') }}">MySQL Users</a>
    <a class="btn small secondary" href="{{ route('mysql-wizard.index') }}">Database Wizard</a>
    <a class="btn small secondary" href="{{ route('phpmyadmin.index') }}">phpMyAdmin</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers create databases here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else

{{-- stats strip --}}
<div class="grid cols-3">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) Databases</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">/ {{ $maxSql }} allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Database users</h3>
        <div class="stat"><span class="num">{{ $users->count() }}</span>
            <span class="unit"><a href="{{ route('mysql-users.index') }}">manage →</a></span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'wrench', 'cls' => 'hico']) Quick start</h3>
        <p class="help" style="margin:6px 0 0"><a href="{{ route('mysql-wizard.index') }}">Database Wizard</a> — DB + user + privileges teen steps me.</p>
    </div>
</div>

{{-- current databases (cPanel-style, Users column ke saath) --}}
<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) Current Databases — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search databases…"
               data-filter-rows="#acp-dbs tbody tr" aria-label="Search databases">
    </div>
    <div class="table-wrap">
        <table id="acp-dbs">
            <thead>
            <tr>
                <th>Database</th>
                <th>Privileged users</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $account->username }}_{{ $row->name }}</td>
                    <td>
                        @php
                            $privUsers = $users->filter(fn ($u) => $u->databases->contains('id', $row->id));
                        @endphp
                        @forelse ($privUsers as $u)
                            <span class="badge green mono">{{ $account->username }}_{{ $u->name }}</span>
                        @empty
                            <span class="muted">koi user nahi — <a href="{{ route('mysql-users.index') }}">add user →</a></span>
                        @endforelse
                    </td>
                    <td class="right">
                        @can('databases.manage')
                            <form method="post" action="{{ route('mysql.destroy', $row) }}" onsubmit="return confirm('DROP {{ $account->username }}_{{ $row->name }}? Saara data DELETE ho jayega — pehle backup lo!')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No databases yet — neeche se pehla database banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Drop karne par privileges auto-revoke hote hain. Users/privileges <a href="{{ route('mysql-users.index') }}">MySQL Users</a> page par.</p>
</div>

{{-- create --}}
@can('databases.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) Create New Database</h3>
    <form method="post" action="{{ route('mysql.store') }}">
        @csrf
        <label for="db-name">New database name</label>
        <div class="row">
            <span class="mono muted">{{ $account->username }}_</span>
            <input id="db-name" name="name" value="{{ old('name') }}" maxlength="16" required placeholder="shop" style="flex:1; max-width:260px">
            <button class="btn" type="submit">+ Create Database</button>
        </div>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <p class="help">Letters/numbers/underscore, 1–16 chars. Asli MariaDB (utf8mb4) — agent socket-auth se banata hai, koi password argv me nahi.</p>
    </form>
</div>
@endcan

{{-- connection info --}}
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Connection settings (apps ke liye)</h3>
    <dl class="kv">
        <dt>Host</dt><dd class="mono">localhost</dd>
        <dt>Port</dt><dd class="mono">3306</dd>
        <dt>Database</dt><dd class="mono">{{ $account->username }}_&lt;name&gt;</dd>
        <dt>User</dt><dd class="mono">{{ $account->username }}_&lt;user&gt;</dd>
    </dl>
    <p class="help">Remote access chahiye to <a href="{{ route('remote-mysql.index') }}">Remote MySQL</a> me host allow karo.</p>
</div>
@endif
@endsection
