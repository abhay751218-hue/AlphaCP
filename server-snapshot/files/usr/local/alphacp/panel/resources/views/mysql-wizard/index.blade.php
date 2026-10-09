@extends('layouts.panel')

@section('title', 'Database Wizard')
@section('subtitle', 'Step-by-step database setup')

@section('actions')
    <a class="btn small secondary" href="{{ route('mysql.index') }}">MySQL Databases</a>
    <a class="btn small secondary" href="{{ route('mysql-users.index') }}">MySQL Users</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) Wizard — {{ $account->username }}</h3>
        <p class="mt">
            <span class="badge {{ $pending ? 'gray' : 'green' }}">Step 1 — Name</span>
            <span class="badge {{ $pending ? 'green' : 'gray' }}">Step 2 — Confirm</span>
        </p>
        <p class="help">Prefix <span class="mono">{{ $account->username }}_</span> · limit MAXSQL {{ $maxSql }}.</p>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Kaise kaam karta hai</h3>
        <p class="help" style="margin:6px 0 0">JSON <span class="mono">~/etc/mysql/databases.json</span> via
            <span class="mono">db.set</span> — no mysql binary, pipe/shell fail-closed. Users/GRANT
            <a href="{{ route('mysql-users.index') }}">MySQL Users</a> me.</p>
    </div>
</div>

@can('databases.manage')
@if ($pending)
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) Step 2 — Confirm</h3>
    <p class="mt">Database: <span class="badge blue mono">{{ $account->username }}_{{ $pending }}</span></p>
    <div class="row mt">
        <form method="post" action="{{ route('mysql-wizard.store') }}">
            @csrf
            <input type="hidden" name="confirm" value="1">
            <button class="btn" type="submit">Create Database</button>
        </form>
        <form method="post" action="{{ route('mysql-wizard.store') }}">
            @csrf
            <input type="hidden" name="cancel" value="1">
            <button class="btn small secondary" type="submit">Cancel</button>
        </form>
    </div>
</div>
@else
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'database', 'cls' => 'hico']) Step 1 — Database Name</h3>
    <form method="post" action="{{ route('mysql-wizard.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="dbw-name">Name</label>
                <div class="row">
                    <span class="mono muted">{{ $account->username }}_</span>
                    <input id="dbw-name" name="name" value="{{ old('name') }}" maxlength="16" required placeholder="shop" style="min-width:140px">
                </div>
            </div>
            <button class="btn" type="submit">Next Step &rarr;</button>
        </div>
        @error('name')<p class="error">{{ $message }}</p>@enderror
    </form>
</div>
@endif
@endcan
@endif
@endsection
