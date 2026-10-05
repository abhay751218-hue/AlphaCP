@extends('layouts.panel')

@section('title', 'Cron Jobs')
@section('subtitle', 'Scheduled tasks — Linux crontab, jailed to this account')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Current jobs</h3>
    <p class="help">{{ $account->username }} · MAXCRON {{ $account->package?->formatLimit('MAXCRON') ?? '—' }}</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Schedule</th>
                <th>Command</th>
                <th></th>
            </tr>
            @forelse ($jobs as $job)
                <tr>
                    <td class="mono">{{ $job->schedule() }}</td>
                    <td class="mono">{{ $job->command }}</td>
                    <td class="right">
                        @can('cron.manage')
                            <form method="post" action="{{ route('cron.destroy', $job) }}" onsubmit="return confirm('Remove this cron job?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No cron jobs yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('cron.manage')
<div class="card mt">
    <h3>New cron job</h3>
    <p class="help">Standard 5 fields. Newlines are not allowed in the command.</p>
    <form method="post" action="{{ route('cron.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="minute">Minute</label>
                <input id="minute" name="minute" required value="{{ old('minute', '0') }}" maxlength="40">
                <label for="hour">Hour</label>
                <input id="hour" name="hour" required value="{{ old('hour', '*') }}" maxlength="40">
                <label for="day">Day</label>
                <input id="day" name="day" required value="{{ old('day', '*') }}" maxlength="40">
            </div>
            <div>
                <label for="month">Month</label>
                <input id="month" name="month" required value="{{ old('month', '*') }}" maxlength="40">
                <label for="weekday">Weekday</label>
                <input id="weekday" name="weekday" required value="{{ old('weekday', '*') }}" maxlength="40">
                <label for="command">Command</label>
                <input id="command" name="command" required maxlength="500" placeholder="/home/{{ $account->username }}/bin/job.sh">
            </div>
        </div>
        <button class="btn mt" type="submit">Add cron</button>
    </form>
</div>
@endcan
@endif
@endsection
