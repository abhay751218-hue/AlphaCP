@extends('layouts.panel')

@section('title', 'Transfer or Restore a cPanel Account')
@section('subtitle', 'WHM cPanel account job — no tar, no rsync, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Transfer or restore a cPanel account</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/backup/cpanel-account.json</span>. Copy later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->action }} · {{ $row->username }}</p>
    @else
        <p class="empty">No cPanel account job yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Queue job</h3>
    <form method="post" action="{{ route('transfer-restore.store') }}" class="stack">
        @csrf
        <label>
            Action
            <select name="action" required>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(old('action', $row?->action ?? 'restore') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Account username
            <input name="username" value="{{ old('username', $row?->username ?? '') }}" maxlength="16" required placeholder="alicehost">
        </label>
        @error('action')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue job</button>
    </form>
</div>
@endcan
@endsection
