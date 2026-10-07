@extends('layouts.panel')

@section('title', match ($panelMode) {
    'whm' => 'Server Manager',
    'reseller' => 'Reseller Workspace',
    default => 'Hosting Account',
})
@section('subtitle', match ($panelMode) {
    'whm' => 'Server health, hosting accounts, packages and operations.',
    'reseller' => 'Apne customers, hosting accounts aur packages ko ek jagah manage karein.',
    default => 'Websites, files, email and databases — your hosting workspace.',
})

@section('actions')
    @if ($panelMode === 'whm')
        <a class="btn small secondary" href="{{ route('system.index', ['refresh' => 1]) }}">Refresh stats</a>
        @can('accounts.view')
            <a class="btn small secondary" href="{{ route('accounts.index') }}">Accounts</a>
        @endcan
        @can('system.tasks')
            <a class="btn small secondary" href="{{ route('system.tasks') }}">Task queue</a>
        @endcan
    @elseif ($panelMode === 'reseller')
        @can('accounts.create')
            <a class="btn small" href="{{ route('accounts.create') }}">＋ New account</a>
        @endcan
        @can('accounts.view')
            <a class="btn small secondary" href="{{ route('accounts.index') }}">Client accounts</a>
        @endcan
    @else
        @can('domains.view')
            <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
        @endcan
        <a class="btn small secondary" href="{{ route('security.index') }}">Security</a>
    @endif
@endsection

@section('content')

@if ($panelMode === 'whm')
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
        <h3>📋 Agent queue</h3>
        <div class="stat"><span class="num">{{ $queue['queued'] + $queue['running'] }}</span>
            <span class="unit">pending · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</span></div>
        <p class="help">paneld v{{ $versions['agent'] }} · {{ $config_server ?? '' }}
            @can('system.tasks') <a href="{{ route('system.tasks') }}">monitor →</a> @endcan</p>
    </div>
</div>

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
@elseif ($panelMode === 'reseller')
<div class="grid cols-4">
    <div class="card">
        <h3>Client accounts</h3>
        <div class="stat"><span class="num">{{ $resellerStats['total'] }}</span><span class="unit">owned by you</span></div>
        <p class="help"><a href="{{ route('accounts.index') }}">View client accounts →</a></p>
    </div>
    <div class="card">
        <h3>Active hosting</h3>
        <div class="stat"><span class="num">{{ $resellerStats['active'] }}</span><span class="unit">active</span></div>
        <div class="meter green"><span style="width: {{ $resellerStats['total'] > 0 ? min(100, (int) round($resellerStats['active'] / $resellerStats['total'] * 100)) : 0 }}%"></span></div>
    </div>
    <div class="card">
        <h3>Needs attention</h3>
        <div class="stat"><span class="num">{{ $resellerStats['pending'] + $resellerStats['suspended'] }}</span><span class="unit">pending / suspended</span></div>
        <p class="help">{{ $resellerStats['pending'] }} pending · {{ $resellerStats['suspended'] }} suspended</p>
    </div>
    <div class="card">
        <h3>Available plans</h3>
        <div class="stat"><span class="num">{{ $resellerStats['packages'] }}</span><span class="unit">visible to your account</span></div>
        <p class="help"><a href="{{ route('packages.index') }}">Browse packages →</a></p>
    </div>
</div>

<div class="card mt">
    <div class="row">
        <div>
            <h3>Recent client accounts</h3>
            <p class="hint">You can only view and manage accounts assigned to your reseller account.</p>
        </div>
        <span class="push"></span>
        @can('accounts.create')
            <a class="btn small" href="{{ route('accounts.create') }}">＋ Create account</a>
        @endcan
    </div>
    <div class="table-wrap">
        <table>
            <tr><th>Customer</th><th>Primary domain</th><th>Package</th><th>Usage</th><th>Status</th><th></th></tr>
            @forelse ($recentResellerAccounts as $clientAccount)
                <tr>
                    <td class="mono">{{ $clientAccount->username }}</td>
                    <td>{{ $clientAccount->main_domain }}</td>
                    <td class="muted">{{ $clientAccount->package?->name ?? '—' }}</td>
                    <td class="muted">{{ number_format((int) $clientAccount->disk_used_mb) }} / {{ $clientAccount->quota_mb < 0 ? '∞' : number_format((int) $clientAccount->quota_mb) }} MB</td>
                    <td><span class="badge {{ $clientAccount->status === 'active' ? 'green' : ($clientAccount->status === 'suspended' ? 'amber' : ($clientAccount->status === 'terminated' ? 'red' : 'blue')) }}">{{ $clientAccount->status }}</span></td>
                    <td class="right"><a class="btn small ghost" href="{{ route('accounts.show', $clientAccount) }}">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Abhi tak koi client account nahi. Pehla account banane ke liye “Create account” par click karein.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@else
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
@endif

@if ($panelMode !== 'reseller')
<div class="card mt">
    <h3>AlphaCP feature progress</h3>
    <div class="stat">
        <span class="num">{{ $progress['live'] }}</span>
        <span class="unit">tools live · {{ $progress['planned'] }} planned · {{ $progress['addon'] }} optional · total {{ $progress['total'] }}</span>
    </div>
    <div class="meter"><span style="width: {{ max(3, $progress['percent']) }}%"></span></div>
    <p class="help">Full checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span> — 208 items,
        har item apne step me live hota jayega.
        @if ($panelMode === 'cpanel')
            Account create / packages are Server Manager (admin) only — they are hidden here.
        @endif
    </p>
</div>
@endif

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
