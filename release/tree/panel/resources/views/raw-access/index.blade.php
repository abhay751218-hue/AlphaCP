@extends('layouts.panel')

@section('title', 'Raw Access')
@section('subtitle', 'Apache access-log — raw entries + summary')

@section('actions')
    <a class="btn small secondary" href="{{ route('errors-log.index') }}">Errors</a>
    <a class="btn small secondary" href="{{ route('awstats.index') }}">Awstats</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) Access log — {{ $account->username }}</h3>
        <div class="stat"><span class="num">{{ count($lines) }}</span><span class="unit">recent raw entries
            @if ($log)<span class="badge blue mono">{{ $log }}</span>@endif</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'chart', 'cls' => 'hico']) Summary (poora log)</h3>
        @if ($stats !== null)
            <p class="mt">
                <span class="badge blue">{{ $stats['requests'] }} requests</span>
                <span class="badge green">{{ $stats['visitors'] }} visitors</span>
                <span class="badge amber">{{ $stats['errors'] }} errors</span>
                <span class="badge gray">{{ $human }}</span>
            </p>
        @else
            <p class="help" style="margin:6px 0 0">Summary available nahi.</p>
        @endif
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) Raw Entries (newest first)</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Filter (IP, path, code…)" data-filter-rows="#acp-rawlog tbody tr" aria-label="Filter lines">
    </div>
    @if (! $agentOk)
        <p class="empty">Agent par terminal.run available nahi — agent update chahiye.</p>
    @elseif (empty($lines))
        <p class="empty">Access log khali hai ya abhi bana nahi — pehla visitor aane par entries dikhengi.</p>
    @else
        <div class="table-wrap">
            <table id="acp-rawlog">
                <thead><tr><th>Log entry</th></tr></thead>
                <tbody>
                @foreach ($lines as $line)
                    <tr><td class="mono" style="font-size:12px; word-break:break-all">{{ $line }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endif
@endsection
