@extends('layouts.panel')

@section('title', 'phpMyAdmin')
@section('subtitle', 'One-click database browser — one-click SSO, password nahi poochta')

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

@if (session('success'))
    <div class="card mb"><p class="help" style="margin:0">✅ {{ session('success') }}</p></div>
@endif
@if ($errors->any())
    <div class="card mb"><p class="empty" style="margin:0">⚠️ {{ $errors->first() }}</p></div>
@endif

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) phpMyAdmin — {{ $account->username }}</h3>
        <p style="margin:10px 0 4px">Status:
            <span class="badge {{ $enabled ? 'green' : 'amber' }}">{{ $enabled ? 'enabled' : 'disabled' }}</span>
            · SSO: <span class="badge {{ $ssoReady ? 'green' : 'amber' }}">{{ $ssoReady ? 'ready' : 'agent update chahiye' }}</span>
            · Port: <span class="badge blue mono">{{ $pmaPort }}</span>
        </p>
        @if ($enabled && $ssoReady)
            <form method="post" action="{{ route('phpmyadmin.open') }}" class="mt">
                @csrf
                <button class="btn" type="submit">🗄️ Open phpMyAdmin</button>
            </form>
            <p class="help" style="margin:8px 0 0">Password nahi poochega — 10-minute ka one-time SSO token banta hai,
                aur access sirf tumhare apne databases par hota hai (<span class="mono">pma_{{ $account->username }}</span>,
                har click par password rotate).</p>
        @elseif (! $enabled)
            <p class="help" style="margin:10px 0 0">Pehle neeche <strong>Access preference</strong> me Enable karke Save karo.</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Ya kisi client se connect karo</h3>
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
