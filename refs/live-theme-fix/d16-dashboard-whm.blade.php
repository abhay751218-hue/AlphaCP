@extends('layouts.panel')

@section('title', config('acp.brand.name','AlphaCP').' — Server Manager Dashboard')
@section('subtitle', 'Server health, accounts, packages — customer sites are not created on this page; they use the account panel')

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
        <h3>@include('partials.icons', ['icon' => 'chip', 'cls' => 'hico']) Memory</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['memory']['used_pct'] }}%</span>
                <span class="unit">used · {{ $system['memory']['used_mb'] }} / {{ $system['memory']['total_mb'] }} MB</span></div>
            <div class="meter {{ $system['memory']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['memory']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">No data from the agent (is paneld running?)</p>
        @endif
    </div>

    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'disk', 'cls' => 'hico']) Disk (/)</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['disk']['used_pct'] }}%</span>
                <span class="unit">{{ $system['disk']['used_gb'] }} / {{ $system['disk']['total_gb'] }} GB</span></div>
            <div class="meter {{ $system['disk']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['disk']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'gauge', 'cls' => 'hico']) Load · CPU</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['load'][0] }}</span>
                <span class="unit">1-min · {{ $system['cpu_cores'] }} cores</span></div>
            <p class="help">{{ $system['os'] }} · kernel {{ $system['kernel'] }} · {{ $system['arch'] }}</p>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'queue', 'cls' => 'hico']) Agent queue</h3>
        <div class="stat"><span class="num">{{ $queue['queued'] + $queue['running'] }}</span>
            <span class="unit">pending · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</span></div>
        <p class="help">paneld v{{ $versions['agent'] }} · {{ $config_server ?? '' }}
            @can('system.tasks') <a href="{{ route('system.tasks') }}">monitor →</a> @endcan</p>
    </div>
</div>

<div class="grid cols-2 mt">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'services', 'cls' => 'hico']) Services</h3>
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
        <h3>@include('partials.icons', ['icon' => 'audit', 'cls' => 'hico']) Recent activity (audit)</h3>
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

@endsection
