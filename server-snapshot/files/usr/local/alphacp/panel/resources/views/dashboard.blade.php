@extends('layouts.panel')

@section('title', 'Dashboard')
@section('subtitle', 'Server health, agent queue aur cPanel-parity progress ek nazar me')

@section('actions')
    <a class="btn small secondary" href="{{ route('system.index', ['refresh' => 1]) }}">Refresh stats</a>
    @can('accounts.view')
        <a class="btn small secondary" href="{{ route('accounts.index') }}">Accounts</a>
    @endcan
    @can('system.tasks')
        <a class="btn small secondary" href="{{ route('system.tasks') }}">Task queue</a>
    @endcan
@endsection

@section('content')

{{-- ---------------------------------------------------------------- stats --}}
<div class="grid cols-4">
    <div class="card">
        <h3>🧠 Memory</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['memory']['used_pct'] }}%</span>
                <span class="unit">used · {{ $system['memory']['used_mb'] }} / {{ $system['memory']['total_mb'] }} MB</span></div>
            <div class="meter {{ $system['memory']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['memory']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">Agent se data nahi aaya (paneld chalu hai?)</p>
        @endif
    </div>

    <div class="card">
        <h3>💾 Disk (/)</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['disk']['used_pct'] }}%</span>
                <span class="unit">{{ $system['disk']['used_gb'] }} / {{ $system['disk']['total_gb'] }} GB</span></div>
            <div class="meter {{ $system['disk']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['disk']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>⚙️ Load · CPU</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['load'][0] }}</span>
                <span class="unit">1-min · {{ $system['cpu_cores'] }} cores</span></div>
            <p class="help">{{ $system['os'] }} · kernel {{ $system['kernel'] }} · {{ $system['arch'] }}</p>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>📋 Agent queue</h3>
        <div class="stat"><span class="num">{{ $queue['queued'] + $queue['running'] }}</span>
            <span class="unit">pending · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</span></div>
        <p class="help">paneld v{{ $versions['agent'] }} · {{ $config_server ?? '' }}
            @can('system.tasks') <a href="{{ route('system.tasks') }}">monitor →</a> @endcan</p>
    </div>
</div>

{{-- ------------------------------------------------------------ services --}}
<div class="grid cols-2 mt">
    <div class="card">
        <h3>🧩 Services</h3>
        @if ($services)
            <div class="table-wrap">
                <table>
                    <tr><th>Service</th><th>State</th><th>Boot</th></tr>
                    @foreach ($services as $name => $state)
                        <tr>
                            <td class="mono">{{ $name }}</td>
                            <td>
                                <span class="badge {{ $state['active'] === 'active' ? 'green' : ($state['active'] === 'inactive' ? 'amber' : 'red') }}">
                                    {{ $state['active'] }}
                                </span>
                            </td>
                            <td class="muted">{{ $state['enabled'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @else
            <p class="empty">Service status agent se nahi aaya.</p>
        @endif
    </div>

    <div class="card">
        <h3>📝 Recent activity (audit)</h3>
        @if ($audit->isEmpty())
            <p class="empty">Abhi koi activity nahi.</p>
        @else
            <div class="table-wrap">
                <table>
                    @foreach ($audit as $event)
                        <tr>
                            <td class="mono">{{ $event->action }}</td>
                            <td><span class="badge {{ $event->severity === 'critical' ? 'red' : ($event->severity === 'warning' ? 'amber' : 'blue') }}">{{ $event->severity }}</span></td>
                            <td class="muted right">{{ \App\Support\Panel::ago($event->created_at) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
            @can('audit.view')
                <p class="help mt"><a href="{{ route('audit.index') }}">Poora audit log →</a></p>
            @endcan
        @endif
    </div>
</div>

{{-- ------------------------------------------------------------- parity --}}
<div class="card mt">
    <h3>🎯 cPanel parity progress</h3>
    <div class="stat">
        <span class="num">{{ $progress['live'] }}</span>
        <span class="unit">tools live · {{ $progress['planned'] }} planned · {{ $progress['addon'] }} optional · total {{ $progress['total'] }}</span>
    </div>
    <div class="meter"><span style="width: {{ max(3, $progress['percent']) }}%"></span></div>
    <p class="help">Poori checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span> — 208 items,
        har item apne step me live hota jayega.</p>
</div>

{{-- ------------------------------------------------------------- tiles --}}
@foreach ($sections as $key => $section)
    @php
        $liveCount = collect($section['items'])->where('status', 'live')->count();
    @endphp
    <div class="section-title">
        <h2>{{ $section['label'] }}</h2>
        <span class="count">{{ $liveCount }} live / {{ count($section['items']) }}</span>
    </div>

    <div class="grid tiles">
        @foreach ($section['items'] as $item)
            @include('partials.tile', ['item' => $item])
        @endforeach
    </div>
@endforeach

@endsection
