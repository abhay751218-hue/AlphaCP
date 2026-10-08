@extends('layouts.panel')

@section('title', $panelMode === 'whm' ? 'Server Manager Dashboard' : 'Account Panel')
@section('subtitle', $panelMode === 'whm'
    ? 'Server health, accounts, packages — customer sites are not created on this page; they use the account panel'
    : 'Files, email, domains, databases — ye aapka hosting control panel hai')

@section('actions')
    @if ($panelMode === 'whm')
        <a class="btn small secondary" href="{{ route('system.index', ['refresh' => 1]) }}">Refresh stats</a>
        @can('accounts.view')
            <a class="btn small secondary" href="{{ route('accounts.index') }}">Accounts</a>
        @endcan
        @can('system.tasks')
            <a class="btn small secondary" href="{{ route('system.tasks') }}">Task queue</a>
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
<div class="card">
    <h3>Quick links</h3>
    <p>
        @can('accounts.create')<a class="btn small" href="{{ route('accounts.create') }}">Create Account</a>@endcan
        @can('accounts.view')<a class="btn small secondary" href="{{ route('accounts.index') }}">List Accounts</a>@endcan
        @can('packages.view')<a class="btn small secondary" href="{{ route('packages.index') }}">Packages</a>@endcan
        @can('accounts.view')<a class="btn small secondary" href="{{ route('transfer-restore.index') }}">Transfer or Restore a Hosting Account</a>@endcan
    </p>
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
@include('partials.dash-sections', [])
@else
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
@endif

@endsection
