@extends('layouts.panel')

@section('title', 'phpMyAdmin')
@section('subtitle', 'Database browser access preference')

@section('actions')
    <a class="btn small secondary" href="{{ route('mysql.index') }}">Databases</a>
    <a class="btn small secondary" href="{{ route('mysql-users.index') }}">MySQL Users</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers set phpMyAdmin here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) phpMyAdmin — {{ $account->username }}</h3>
        <p style="margin:10px 0 4px">Status:
            <span class="badge {{ $enabled ? 'green' : 'amber' }}">{{ $enabled ? 'enabled' : 'disabled' }}</span>
        </p>
        <p class="help">Preference JSON <span class="mono">~/etc/mysql/phpmyadmin.json</span> me sync hoti hai.
            phpMyAdmin app + one-click SSO agle update me aayega — tab tak neeche wale connection
            details se koi bhi MySQL client (HeidiSQL, DBeaver, TablePlus, mysql CLI) use karo.</p>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Connect via client</h3>
        <dl class="kv">
            <dt>Host</dt><dd class="mono">localhost <span class="muted">(ya server IP — Remote MySQL allow karke)</span></dd>
            <dt>Port</dt><dd class="mono">3306</dd>
            <dt>User</dt><dd class="mono">{{ $account->username }}_&lt;user&gt;</dd>
            <dt>Password</dt><dd><a href="{{ route('mysql-users.index') }}">MySQL Users</a> me set/reset hota hai</dd>
        </dl>
    </div>
</div>

@can('databases.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Access preference</h3>
    <form method="post" action="{{ route('phpmyadmin.store') }}">
        @csrf
        <div class="row" style="align-items:flex-end">
            <div>
                <label for="enabled">phpMyAdmin access</label>
                <select id="enabled" name="enabled" required style="min-width:160px">
                    <option value="0" @selected(! $enabled)>Disabled</option>
                    <option value="1" @selected($enabled)>Enabled</option>
                </select>
            </div>
            <button class="btn" type="submit">Save</button>
        </div>
        @error('enabled')<p class="error">{{ $message }}</p>@enderror
    </form>
</div>
@endcan
@endif
@endsection
