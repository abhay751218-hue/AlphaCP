@extends('layouts.panel')

@section('title', 'Backup Config')
@section('subtitle', 'Server Manager schedule and retention — no tar, no pipe')

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

<div class="card mt">
    <h3>Scheduled backups (cron)</h3>
    <p class="help">Installer <span class="mono">/etc/cron.d/alphacp-panel</span> har minute
        <span class="mono">php artisan schedule:run</span> chalata hai; <span class="mono">alphacp:scheduled-backups</span>
        har ghante tick karta hai aur sirf apne window (daily/weekly/monthly) me asli archives queue karta hai.
        Retention parhne ke baad agent purane archives khud prune karta hai.</p>
    @if ($scheduler['schedule'] === 'disabled')
        <p class="empty">Abhi <span class="mono">disabled</span> hai — schedule daily/weekly/monthly set karo, tab hi cron archives banayega.</p>
    @else
        <p class="mono">schedule {{ $scheduler['schedule'] }} · window {{ $scheduler['window'] ?? '—' }} · retention {{ $scheduler['retention'] ?? '—' }} days</p>
        <p class="mono mt">
            last run: {{ $scheduler['last_run_at'] ?? '—' }}
            @if ($scheduler['last_window']) (window {{ $scheduler['last_window'] }})@endif
            · queued {{ $scheduler['queued'] ?? '—' }} · skipped {{ $scheduler['skipped'] ?? '—' }} · failed {{ $scheduler['failed'] ?? '—' }}
        </p>
        <p class="mono">
            next run: {{ $scheduler['next_due_at'] ?? 'window band hai — khulega ' . ($scheduler['schedule'] === 'daily' ? 'kal' : ($scheduler['schedule'] === 'weekly' ? 'agle ISO week' : 'agle mahine')) }}
        </p>
        @if ($scheduler['errors'])
            @foreach ($scheduler['errors'] as $error)
                <p class="error">{{ $error }}</p>
            @endforeach
        @endif
    @endif
    <p class="help mt">Marker: <span class="mono">{{ $scheduler['marker'] }}</span> ·
        Backup User Selection rows: <span class="mono">{{ $selectedUsers }}</span>
        @if ($selectedUsers > 0)(sirf ye usernames scheduled backups me jaate hain)@else(rows 0 = saare active accounts)@endif</p>
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
