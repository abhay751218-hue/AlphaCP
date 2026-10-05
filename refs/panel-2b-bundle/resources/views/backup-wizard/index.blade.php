@extends('layouts.panel')

@section('title', 'Backup Wizard')
@section('subtitle', 'Guided backup or restore — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers set a backup/restore plan here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Backup Wizard — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/backup/wizard.json</span>. No tar/shell. Action/scope allowlist — pipe/path fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->action }} · {{ $row->scope }}</p>
    @else
        <p class="empty">No wizard plan yet.</p>
    @endif
</div>

@can('files.manage')
<div class="card mt">
    <h3>Set wizard plan</h3>
    <form method="post" action="{{ route('backup-wizard.store') }}" class="stack">
        @csrf
        <label>
            Action
            <select name="action" required>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(old('action', $row?->action ?? 'backup') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Scope
            <select name="scope" required>
                @foreach ($scopes as $scope)
                    <option value="{{ $scope }}" @selected(old('scope', $row?->scope ?? 'home') === $scope)>{{ $scope }}</option>
                @endforeach
            </select>
        </label>
        @error('action')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save wizard</button>
    </form>
</div>
@endcan
@endif
@endsection
