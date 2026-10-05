@extends('layouts.panel')

@section('title', 'phpMyAdmin')
@section('subtitle', 'Enabled preference — no phpMyAdmin install, no SSO, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers set phpMyAdmin here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>phpMyAdmin — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mysql/phpmyadmin.json</span>. phpMyAdmin app / SSO later. Pipe/shell fail closed.</p>
    <p>Status: <strong>{{ $enabled ? 'on' : 'off' }}</strong></p>
</div>

@can('databases.manage')
<div class="card mt">
    <h3>Update</h3>
    <form method="post" action="{{ route('phpmyadmin.store') }}">
        @csrf
        <label for="enabled">Enabled</label>
        <select id="enabled" name="enabled" required>
            <option value="0" @selected(! $enabled)>off</option>
            <option value="1" @selected($enabled)>on</option>
        </select>
        @error('enabled')<p class="error">{{ $message }}</p>@enderror
        <button class="btn mt" type="submit">Save</button>
    </form>
</div>
@endcan
@endif
@endsection
