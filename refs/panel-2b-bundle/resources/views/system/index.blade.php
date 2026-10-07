@extends('layouts.panel')

@section('title', 'System Information')
@section('subtitle', 'Server facts — root agent (paneld) se live aate hain')

@section('actions')
    <a class="btn small" href="{{ route('system.index', ['refresh' => 1]) }}">Refresh</a>
    <a class="btn small secondary" href="{{ route('system.services') }}">Services</a>
    <a class="btn small secondary" href="{{ route('system.tasks') }}">Task queue</a>
@endsection

@section('content')

<div class="grid cols-2">
    <div class="card">
        <h3>🖥️ Host</h3>
        @if ($system)
            <dl class="kv">
                <dt>Hostname</dt><dd class="mono">{{ $system['hostname'] }}</dd>
                <dt>OS</dt><dd>{{ $system['os'] }}</dd>
                <dt>Kernel</dt><dd class="mono">{{ $system['kernel'] }}</dd>
                <dt>Architecture</dt><dd class="mono">{{ $system['arch'] }}</dd>
                <dt>Uptime</dt><dd>{{ intdiv($system['uptime_sec'], 86400) }} din {{ intdiv($system['uptime_sec'] % 86400, 3600) }} ghante</dd>
                <dt>Public IP</dt><dd class="mono">{{ $server['public_ip'] ?? '-' }}</dd>
                <dt>Collected</dt><dd class="muted">{{ $system['collected_at'] }} (UTC)</dd>
            </dl>
        @else
            <p class="empty">No agent response — check <span class="mono">systemctl status paneld</span>.</p>
        @endif
    </div>

    <div class="card">
        <h3>📈 Resources</h3>
        @if ($system)
            <dl class="kv">
                <dt>CPU cores</dt><dd>{{ $system['cpu_cores'] }}</dd>
                <dt>Load (1/5/15)</dt><dd class="mono">{{ implode(' · ', $system['load']) }}</dd>
                <dt>Memory</dt><dd>{{ $system['memory']['used_mb'] }} / {{ $system['memory']['total_mb'] }} MB ({{ $system['memory']['used_pct'] }}%)</dd>
                <dt>Swap</dt><dd>{{ $system['swap']['used_mb'] }} / {{ $system['swap']['total_mb'] }} MB</dd>
                <dt>Disk (/)</dt><dd>{{ $system['disk']['used_gb'] }} / {{ $system['disk']['total_gb'] }} GB ({{ $system['disk']['used_pct'] }}%)</dd>
            </dl>
            <div class="meter {{ $system['memory']['used_pct'] > 85 ? 'amber' : 'green' }} mt"><span style="width: {{ min(100, $system['memory']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>
</div>

<div class="grid cols-2 mt">
    <div class="card">
        <h3>🧾 Server record (panel DB)</h3>
        @if ($server)
            <dl class="kv">
                <dt>Name</dt><dd>{{ $server['name'] }}</dd>
                <dt>Role</dt><dd>{{ $server['role'] }}</dd>
                <dt>Panel version</dt><dd class="mono">{{ $server['panel_version'] ?? '-' }}</dd>
                <dt>Registered</dt><dd class="muted">{{ $server['created_at'] }}</dd>
            </dl>
        @else
            <p class="empty">Server row not found.</p>
        @endif
    </div>

    <div class="card">
        <h3>📦 Versions</h3>
        <dl class="kv">
            <dt>Panel</dt><dd class="mono">{{ $versions['panel'] }}</dd>
            <dt>paneld (agent)</dt><dd class="mono">{{ $versions['agent'] }}</dd>
            <dt>PHP</dt><dd class="mono">{{ $versions['php'] }}</dd>
            <dt>Framework</dt><dd class="mono">Laravel {{ $versions['framework'] }}</dd>
        </dl>
    </div>
</div>

@endsection
