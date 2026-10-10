@extends('layouts.panel')

@section('title', 'Awstats')
@section('subtitle', 'Visitor statistics — access log se (bars + totals)')

@section('actions')
    <a class="btn small secondary" href="{{ route('metrics.index') }}">Metrics</a>
    <a class="btn small secondary" href="{{ route('raw-access.index') }}">Raw Access</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

@if ($stats === null)
<div class="card"><p class="empty">Stats available nahi — agent par metrics.access chahiye.</p></div>
@else
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'chart', 'cls' => 'hico']) Traffic — {{ $account->username }}</h3>
        <div class="row mt" style="gap:20px; flex-wrap:wrap">
            <div class="stat"><span class="num">{{ $stats['visitors'] }}</span><span class="unit">unique visitors</span></div>
            <div class="stat"><span class="num">{{ $stats['requests'] }}</span><span class="unit">requests</span></div>
            <div class="stat"><span class="num">{{ $human }}</span><span class="unit">bandwidth</span></div>
        </div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'alert', 'cls' => 'hico']) Error rate</h3>
        @php
            $reqs = max(1, (int) $stats['requests']);
            $errPct = (int) min(100, round(((int) $stats['errors']) / $reqs * 100));
        @endphp
        <div class="stat"><span class="num">{{ $stats['errors'] }}</span><span class="unit">errors (4xx/5xx)
            <span class="badge {{ $errPct >= 20 ? 'red' : ($errPct >= 5 ? 'amber' : 'green') }}">{{ $errPct }}%</span></span></div>
        <div class="meter mt"><span style="width: {{ max(2, $errPct) }}%"></span></div>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'chart', 'cls' => 'hico']) Top Pages</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search pages…" data-filter-rows="#acp-awstats tbody tr" aria-label="Search pages">
    </div>
    @if (empty($top))
        <p class="empty">Abhi koi traffic nahi — pehla visitor aane par graphs yahan dikhenge.</p>
    @else
        <div class="table-wrap">
            <table id="acp-awstats">
                <thead><tr><th>Page</th><th style="width:45%">Hits</th><th class="right">Count</th></tr></thead>
                <tbody>
                @foreach ($top as $path => $hits)
                    <tr>
                        <td class="mono" style="word-break:break-all">{{ $path }}</td>
                        <td><div class="meter"><span style="width: {{ max(3, (int) round($hits / $maxHit * 100)) }}%"></span></div></td>
                        <td class="right"><span class="badge blue">{{ $hits }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endif
@endif
@endsection
