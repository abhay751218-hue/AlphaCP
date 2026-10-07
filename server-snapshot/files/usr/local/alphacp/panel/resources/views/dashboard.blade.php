@extends('layouts.panel')

@php
    /**
     * cPanel-parity home (docs/10-ui-parity-design.md):
     *   WHM mode  → WHM Home jaisa: Favorites + Statistics + server cards + tool categories
     *   cPanel mode → cPanel "Tools" jaisa: section-wise icon grid (right me General Information/Statistics)
     */
    $isWhm = $panelMode === 'whm';
@endphp

@section('title', $isWhm ? 'WHM Dashboard' : 'Tools')
@section('subtitle', $isWhm
    ? 'Server health, accounts, packages — customer websites yahan se nahi bante; wo account panel (cPanel) se chalte hain'
    : 'Files, email, domains, databases — sab kuch yahin se manage hota hai')

@section('actions')
    @if ($isWhm)
        <a class="btn small secondary" href="{{ route('system.index', ['refresh' => 1]) }}">Refresh stats</a>
        @can('accounts.view')
            <a class="btn small secondary" href="{{ route('accounts.index') }}">List Accounts</a>
        @endcan
        @can('system.tasks')
            <a class="btn small secondary" href="{{ route('system.tasks') }}">Task Queue Monitor</a>
        @endcan
    @else
        @can('domains.view')
            <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
        @endcan
        <a class="btn small secondary" href="{{ route('security.index') }}">Security</a>
    @endif
@endsection

@section('content')

@if ($isWhm)

    {{-- ------------------------------------------------ Favorites (WHM home) --}}
    <div class="section-title">
        <h2>Favorites</h2>
        <span class="count">quick access</span>
    </div>
    <div class="cp-tiles compact">
        @can('accounts.view')
            <a class="cp-tile live" href="{{ route('accounts.index') }}">
                <span class="cp-tile-icon">&#128203;</span>
                <span class="cp-tile-body"><span class="name">List Accounts</span><span class="sub">Manage</span></span>
            </a>
            <a class="cp-tile live" href="{{ route('accounts.create') }}">
                <span class="cp-tile-icon">&#10133;</span>
                <span class="cp-tile-body"><span class="name">Create a New Account</span><span class="sub">Manage</span></span>
            </a>
        @endcan
        @can('packages.view')
            <a class="cp-tile live" href="{{ route('packages.index') }}">
                <span class="cp-tile-icon">&#128230;</span>
                <span class="cp-tile-body"><span class="name">Packages</span><span class="sub">Manage</span></span>
            </a>
        @endcan
        @can('dnszones.view')
            <a class="cp-tile live" href="{{ route('dns-zones.index') }}">
                <span class="cp-tile-icon">&#127760;</span>
                <span class="cp-tile-body"><span class="name">DNS Zone Manager</span><span class="sub">Manage</span></span>
            </a>
        @endcan
        @can('system.tasks')
            <a class="cp-tile live" href="{{ route('system.tasks') }}">
                <span class="cp-tile-icon">&#128225;</span>
                <span class="cp-tile-body"><span class="name">Process Manager</span><span class="sub">Monitor</span></span>
            </a>
        @endcan
        <a class="cp-tile live" href="{{ route('audit.index') }}">
            <span class="cp-tile-icon">&#128220;</span>
            <span class="cp-tile-body"><span class="name">Audit Log</span><span class="sub">Inspect</span></span>
        </a>
    </div>

    {{-- ----------------------------------------------- Statistics (WHM home) --}}
    <div class="section-title">
        <h2>Statistics</h2>
        <span class="count">{{ $server['hostname'] ?? 'server' }}</span>
    </div>
    <div class="grid cols-4">
        <div class="card">
            <h3>🧠 Memory</h3>
            @if ($system)
                <div class="stat"><span class="num">{{ $system['memory']['used_pct'] }}%</span>
                    <span class="unit">used · {{ $system['memory']['used_mb'] }} / {{ $system['memory']['total_mb'] }} MB</span></div>
                <div class="meter {{ $system['memory']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['memory']['used_pct']) }}%"></span></div>
            @else
                <p class="empty">No data from the agent (is paneld running?)</p>
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
            <h3>📋 Task Queue Monitor</h3>
            <div class="stat"><span class="num">{{ $queue['queued'] + $queue['running'] }}</span>
                <span class="unit">pending · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</span></div>
            <p class="help">paneld v{{ $versions['agent'] }} · {{ $config_server ?? '' }}
                @can('system.tasks') <a href="{{ route('system.tasks') }}">monitor →</a> @endcan</p>
        </div>
    </div>

    <div class="grid cols-2 mt">
        <div class="card">
            <h3>🧩 Service Status</h3>
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
                <p class="empty">Service status did not come from the agent.</p>
            @endif
        </div>

        <div class="card">
            <h3>📝 Recent activity (audit)</h3>
            @if ($audit->isEmpty())
                <p class="empty">No activity yet.</p>
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

@else

    {{-- ------------------------------------------------------- cPanel "Tools" --}}
    <div class="cp-hero card">
        <div>
            <h3 class="mt0">Welcome, {{ auth()->user()?->username }}</h3>
            <p class="help">
                @if ($account)
                    {{ $account->main_domain }} · package <strong>{{ $account->package?->name ?? '—' }}</strong> · PHP {{ $account->php_version }}
                @else
                    Aapka hosting account abhi link nahi hai — provider se sampark karo.
                @endif
            </p>
        </div>
        <div class="cp-hero-actions">
            @can('files.view')<a class="btn small" href="{{ route('files.index') }}">File Manager</a>@endcan
            @can('email.view')<a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>@endcan
        </div>
    </div>

@endif

@foreach ($sections as $key => $section)
    @php
        $liveCount = collect($section['items'])->where('status', 'live')->count();
    @endphp
    <div class="section-title" id="section-{{ $key }}">
        <h2>{{ $section['label'] }}</h2>
        <span class="count">{{ $liveCount }} live / {{ count($section['items']) }}</span>
    </div>

    <div class="cp-tiles">
        @foreach ($section['items'] as $item)
            @include('partials.tile', ['item' => $item, 'icon' => $section['icon'] ?? 'cog'])
        @endforeach
    </div>
@endforeach

<div class="card mt cp-progress">
    <h3>🎯 AlphaCP feature progress</h3>
    <div class="stat">
        <span class="num">{{ $progress['live'] }}</span>
        <span class="unit">tools live · {{ $progress['planned'] }} planned · {{ $progress['addon'] }} optional · total {{ $progress['total'] }}</span>
    </div>
    <div class="meter"><span style="width: {{ max(3, $progress['percent']) }}%"></span></div>
    <p class="help">Full checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span> — 208 items,
        har item apne step me live hota jayega.
        @if (! $isWhm)
            Account create / packages sirf Server Manager (WHM) me hote hain — yahan nahi.
        @endif
    </p>
</div>

@endsection
