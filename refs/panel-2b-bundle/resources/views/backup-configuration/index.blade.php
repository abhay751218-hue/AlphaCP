@extends('layouts.panel')

@section('title', 'Backup Configuration')
@section('subtitle', 'WHM schedule, retention and destination — no tar, no shell, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Backup configuration</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/backup/config.json</span>. Tar/copy engine later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">
            {{ $row->enabled ? 'enabled' : 'disabled' }} · {{ $row->schedule }} ·
            keep {{ $row->retention_days }} days · {{ $row->destination }}
            @if ($row->destination === 'remote')
                · {{ $row->remote_user . '@' . $row->remote_host . '/' . $row->remote_path }}
            @endif
        </p>
    @else
        <p class="empty">No backup configuration yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Set backup configuration</h3>
    <form method="post" action="{{ route('backup-configuration.store') }}" class="stack">
        @csrf
        <label>
            Enable scheduled backups
            <select name="enabled" required>
                @foreach (['on' => 'enabled', 'off' => 'disabled'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('enabled', $row && $row->enabled ? 'on' : 'off') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Schedule
            <select name="schedule" required>
                @foreach ($schedules as $schedule)
                    <option value="{{ $schedule }}" @selected(old('schedule', $row?->schedule ?? 'daily') === $schedule)>{{ $schedule }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Retention (days)
            <input name="retention_days" value="{{ old('retention_days', $row?->retention_days ?? 30) }}" maxlength="4" required>
        </label>
        <label>
            Destination
            <select name="destination" required>
                @foreach ($destinations as $destination)
                    <option value="{{ $destination }}" @selected(old('destination', $row?->destination ?? 'local') === $destination)>{{ $destination }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Remote host (IPv4/FQDN — only for remote)
            <input name="remote_host" value="{{ old('remote_host', $row?->remote_host ?? '') }}" maxlength="190" placeholder="backup.example.com">
        </label>
        <label>
            Remote user
            <input name="remote_user" value="{{ old('remote_user', $row?->remote_user ?? '') }}" maxlength="64" placeholder="acpbackup">
        </label>
        <label>
            Remote path (relative)
            <input name="remote_path" value="{{ old('remote_path', $row?->remote_path ?? '') }}" maxlength="190" placeholder="backups/server1">
        </label>
        @error('schedule')<p class="error">{{ $message }}</p>@enderror
        @error('remote_host')<p class="error">{{ $message }}</p>@enderror
        @error('remote_user')<p class="error">{{ $message }}</p>@enderror
        @error('remote_path')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save backup configuration</button>
    </form>
</div>
@endcan
@endsection
