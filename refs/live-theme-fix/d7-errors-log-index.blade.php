@extends('layouts.panel')

@section('title', 'Errors')
@section('subtitle', 'Apache error-log ki aakhri entries — newest first')

@section('actions')
    <a class="btn small secondary" href="{{ route('raw-access.index') }}">Raw Access</a>
    <a class="btn small secondary" href="{{ route('metrics.index') }}">Metrics</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'alert', 'cls' => 'hico']) Error log — {{ $account->username }}</h3>
        <div class="stat"><span class="num">{{ count($lines) }}</span><span class="unit">recent entries
            @if ($log)<span class="badge blue mono">{{ $log }}</span>@endif</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'wrench', 'cls' => 'hico']) Kaise padhein</h3>
        <p class="help" style="margin:6px 0 0">Sabse nayi entry sabse upar. <span class="mono">Permission denied</span> =
            file permissions check karo · <span class="mono">script timed out</span> = PHP limits
            (<a href="{{ route('php.ini') }}">INI Editor</a>) · 404 spam = bots, fikar nahi.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'alert', 'cls' => 'hico']) Latest Errors</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Filter lines…" data-filter-rows="#acp-errlog tbody tr" aria-label="Filter lines">
    </div>
    @if (! $agentOk)
        <p class="empty">Agent par terminal.run available nahi — agent update chahiye.</p>
    @elseif (empty($lines))
        <p class="empty">Error log khali hai ya abhi bana nahi — koi error nahi, ye achhi baat hai! 🎉</p>
    @else
        <div class="table-wrap">
            <table id="acp-errlog">
                <thead><tr><th>Log entry (newest first)</th></tr></thead>
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
