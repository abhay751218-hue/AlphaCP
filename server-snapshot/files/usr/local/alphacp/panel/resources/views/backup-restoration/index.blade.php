@extends('layouts.panel')

@section('title', 'Backup Restoration')
@section('subtitle', 'Server Manager full / partial / per-account — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Backup restoration</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/backup/restoration.json</span>. Tar later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->mode }} · {{ $row->username }}</p>
    @else
        <p class="empty">No backup restoration yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Queue restoration</h3>
    <form method="post" action="{{ route('backup-restoration.store') }}" class="stack">
        @csrf
        <label>
            Mode
            <select name="mode" required>
                @foreach ($modes as $mode)
                    <option value="{{ $mode }}" @selected(old('mode', $row?->mode ?? 'full') === $mode)>{{ $mode }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Account username
            <input name="username" value="{{ old('username', $row?->username ?? '') }}" maxlength="16" required placeholder="alicehost">
        </label>
        @error('mode')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue restoration</button>
    </form>
</div>
@endcan
@endsection
