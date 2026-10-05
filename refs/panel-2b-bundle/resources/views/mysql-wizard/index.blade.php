@extends('layouts.panel')

@section('title', 'Database Wizard')
@section('subtitle', 'Step-by-step name — existing db.set, no mysql binary')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers create databases here with the wizard.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Database Wizard — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mysql/databases.json</span> via existing <span class="mono">db.set</span>. Prefix <span class="mono">{{ $account->username }}_</span>. Users/GRANT later. Pipe/shell fail closed. MAXSQL {{ $maxSql }}.</p>
</div>

@can('databases.manage')
@if ($pending)
<div class="card mt">
    <h3>Step 2 — Confirm</h3>
    <p>Database: <span class="mono">{{ $account->username }}_{{ $pending }}</span></p>
    <form method="post" action="{{ route('mysql-wizard.store') }}" class="stack">
        @csrf
        <input type="hidden" name="confirm" value="1">
        <button class="btn" type="submit">Create Database</button>
    </form>
    <form method="post" action="{{ route('mysql-wizard.store') }}" class="mt">
        @csrf
        <input type="hidden" name="cancel" value="1">
        <button class="btn small secondary" type="submit">Cancel</button>
    </form>
</div>
@else
<div class="card mt">
    <h3>Step 1 — Database name</h3>
    <form method="post" action="{{ route('mysql-wizard.store') }}" class="stack">
        @csrf
        <label>
            Name
            <span class="mono">{{ $account->username }}_</span>
            <input name="name" value="{{ old('name') }}" maxlength="16" required placeholder="shop">
        </label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Next</button>
    </form>
</div>
@endif
@endcan
@endif
@endsection
