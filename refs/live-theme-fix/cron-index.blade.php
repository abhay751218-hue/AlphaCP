@extends('layouts.panel')

@section('title', 'Cron Jobs')
@section('subtitle', 'Scheduled tasks — Linux crontab, jailed to this account')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else

{{-- stats strip --}}
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'clock', 'cls' => 'hico']) Cron jobs</h3>
        <div class="stat"><span class="num">{{ $jobs->count() }}</span>
            <span class="unit">/ {{ $account->package?->formatLimit('MAXCRON') ?? '—' }} allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'terminal', 'cls' => 'hico']) Kaise chalta hai</h3>
        <p class="help" style="margin:6px 0 0">Crontab account user ke naam se install hota hai — commands sirf aapke
            home me chalte hain. Output chahiye to command me <span class="mono">&gt;&gt; ~/logs/job.log 2&gt;&amp;1</span> jodo.</p>
    </div>
</div>

{{-- current jobs --}}
<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'clock', 'cls' => 'hico']) Current Cron Jobs — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search cron jobs…"
               data-filter-rows="#acp-cron tbody tr" aria-label="Search cron jobs">
    </div>
    <div class="table-wrap">
        <table id="acp-cron">
            <thead>
            <tr>
                <th>Schedule (min hr day mon wk)</th>
                <th>Command</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($jobs as $job)
                <tr>
                    <td><span class="badge blue mono">{{ $job->schedule() }}</span></td>
                    <td class="mono" style="word-break:break-all">{{ $job->command }}</td>
                    <td class="right">
                        @can('cron.manage')
                            <form method="post" action="{{ route('cron.destroy', $job) }}" onsubmit="return confirm('Remove this cron job?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No cron jobs yet — neeche se pehla job banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- add job (cPanel style with Common Settings) --}}
@can('cron.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'clock', 'cls' => 'hico']) Add New Cron Job</h3>
    <form method="post" action="{{ route('cron.store') }}">
        @csrf
        <label for="cron-preset">Common Settings</label>
        <select id="cron-preset" data-cron-preset style="max-width:360px">
            <option value="">— choose a preset (ya neeche khud bharo) —</option>
            <option value="* * * * *">Once Per Minute ( * * * * * )</option>
            <option value="*/5 * * * *">Once Per Five Minutes ( */5 * * * * )</option>
            <option value="0,30 * * * *">Twice Per Hour ( 0,30 * * * * )</option>
            <option value="0 * * * *">Once Per Hour ( 0 * * * * )</option>
            <option value="0 0,12 * * *">Twice Per Day ( 0 0,12 * * * )</option>
            <option value="0 0 * * *">Once Per Day ( 0 0 * * * )</option>
            <option value="0 0 * * 0">Once Per Week ( 0 0 * * 0 )</option>
            <option value="0 0 1,15 * *">On the 1st and 15th ( 0 0 1,15 * * )</option>
            <option value="0 0 1 * *">Once Per Month ( 0 0 1 * * )</option>
            <option value="0 0 1 1 *">Once Per Year ( 0 0 1 1 * )</option>
        </select>
        <div class="grid cols-2 mt">
            <div>
                <label for="minute">Minute</label>
                <input id="minute" name="minute" required value="{{ old('minute', '0') }}" maxlength="40" data-cron-field="0">
                <label for="hour">Hour</label>
                <input id="hour" name="hour" required value="{{ old('hour', '*') }}" maxlength="40" data-cron-field="1">
                <label for="day">Day</label>
                <input id="day" name="day" required value="{{ old('day', '*') }}" maxlength="40" data-cron-field="2">
            </div>
            <div>
                <label for="month">Month</label>
                <input id="month" name="month" required value="{{ old('month', '*') }}" maxlength="40" data-cron-field="3">
                <label for="weekday">Weekday</label>
                <input id="weekday" name="weekday" required value="{{ old('weekday', '*') }}" maxlength="40" data-cron-field="4">
                <label for="command">Command</label>
                <input id="command" name="command" required maxlength="500" placeholder="php /home/{{ $account->username }}/public_html/artisan schedule:run">
            </div>
        </div>
        @error('command')<p class="error">{{ $message }}</p>@enderror
        <button class="btn mt" type="submit">+ Add New Cron Job</button>
        <p class="help">Fields: numbers, <span class="mono">*</span>, <span class="mono">,</span> <span class="mono">-</span> <span class="mono">/</span>. Command me newline allowed nahi. Weekday: 0 = Sunday.</p>
    </form>
</div>
@endcan
@endif
@endsection
