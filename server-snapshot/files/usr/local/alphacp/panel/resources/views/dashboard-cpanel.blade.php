@extends('layouts.panel')

@section('title', 'cPanel — Account Panel')
@section('subtitle', 'Files, email, domains, databases — ye aapka hosting control panel hai')

@section('actions')
    @can('domains.view')
        <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
    @endcan
    <a class="btn small secondary" href="{{ route('security.index') }}">Security</a>
@endsection

@section('content')
<div class="grid cols-4">
<div class="dash-cols">
    <div>
        <div class="grid cols-4">
    <div class="card">
        <h3>🌐 Primary domain</h3>
        @if ($account)
            <div class="stat"><span class="num" style="font-size:18px">{{ $account->main_domain }}</span></div>
            <p class="help">user <span class="mono">{{ $account->username }}</span> · {{ $account->status }}</p>
        @else
            <p class="empty">No hosting account is linked to this login. Ask your provider.</p>
        @endif
    </div>
    <div class="card">
        <h3>💾 Disk quota</h3>
        @if ($account)
            <div class="stat"><span class="num">{{ $account->disk_used_mb }}</span>
                <span class="unit">MB used · {{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>
    <div class="card">
        <h3>📦 Package</h3>
        @if ($account)
            <div class="stat"><span class="num" style="font-size:18px">{{ $account->package?->name ?? '—' }}</span></div>
            <p class="help">PHP {{ $account->php_version }}</p>
        @else
            <p class="empty">—</p>
        @endif
    </div>
    <div class="card">
        <h3>🌍 Domains</h3>
        @if ($account)
            <div class="stat"><span class="num">{{ $account->domains->count() }}</span>
                <span class="unit">on this account</span></div>
            <p class="help"><a href="{{ route('domains.index') }}">Manage domains →</a></p>
        @else
            <p class="empty">—</p>
        @endif
    </div>
</div>
        @include('partials.dash-sections', [])
    </div>
    <aside class="dash-side">
        <div class="card">
            <h3>General Information</h3>
            @if ($account)
                <dl class="kv">
                    <dt>User</dt><dd class="mono">{{ $account->username }}</dd>
                    <dt>Primary domain</dt><dd>{{ $account->main_domain }}</dd>
                    <dt>Home directory</dt><dd class="mono">{{ $account->home_path }}</dd>
                    <dt>PHP version</dt><dd>{{ $account->php_version }}</dd>
                    <dt>Package</dt><dd>{{ $account->package?->name ?? '—' }}</dd>
                    <dt>Status</dt><dd>{{ $account->status }}</dd>
                    <dt>Theme</dt><dd>Paper (cPanel-style)</dd>
                    <dt>Panel version</dt><dd>{{ config('acp.version') }}</dd>
                </dl>
            @else
                <p class="empty">No hosting account is linked to this login. Ask your provider.</p>
            @endif
        </div>
        <div class="card">
            <h3>Statistics</h3>
            @if ($account)
                @php $pct = $account->quota_mb > 0 ? min(100, (int) round($account->disk_used_mb * 100 / $account->quota_mb)) : 0; @endphp
                <p class="help">Disk usage</p>
                <div class="stat"><span class="num">{{ $account->disk_used_mb }}</span>
                    <span class="unit">MB / {{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</span></div>
                <div class="meter {{ $pct > 85 ? 'amber' : 'green' }}"><span style="width: {{ max(2, $pct) }}%"></span></div>
                <dl class="kv mt">
                    <dt>Bandwidth</dt><dd>{{ $account->bw_used_mb }} MB is cycle me</dd>
                    <dt>Domains</dt><dd>{{ $account->domains->count() }} is account par</dd>
                </dl>
            @else
                <p class="empty">—</p>
            @endif
        </div>
    </aside>
</div>

@endsection

