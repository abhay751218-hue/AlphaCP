@extends('layouts.panel')

@section('title', 'Backup Config')
@section('subtitle', 'WHM schedule and retention — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Backup config</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/backup/config.json</span>. Remote later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->schedule }} · {{ $row->retention }} days</p>
    @else
        <p class="empty">No backup config yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Set backup config</h3>
    <form method="post" action="{{ route('backup-config.store') }}" class="stack">
        @csrf
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
            <input name="retention" value="{{ old('retention', $row?->retention ?? '14') }}" maxlength="8" required placeholder="14">
        </label>
        @error('schedule')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save config</button>
    </form>
</div>
@endcan
@endsection
