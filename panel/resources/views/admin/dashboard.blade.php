@extends('layouts.whm')

@section('title', 'Dashboard')

@section('content')
<div class="page-head">
  <h1>Dashboard</h1>
  <span class="badge-whm">WHM</span>
  <span class="sub">server-level view · parity rows 94–182</span>
  @if ($sysState !== 'success')
    <span class="chip"><span class="dot warn"></span> agent {{ $sysState }}</span>
  @endif
</div>

<div class="dash-grid">
  <div class="dash-main">
    {{-- ===== stat cards ===== --}}
    <div class="grid cols-4">
      <div class="card">
        <h3><span class="stat-ico">@include('partials.icon', ['name' => 'users', 'size' => 15])</span> Accounts</h3>
        <div class="big">{{ $accountStats['active'] }}<span class="sub" style="font-size:14px"> / {{ $accountStats['total'] }}</span></div>
        <div class="sub">{{ $accountStats['suspended'] }} suspended · {{ $accountStats['pending'] }} pending</div>
        @unless ($accountsReady)
          <div class="sub muted" style="margin-top:8px">accounts table arrives with Step S3</div>
        @endunless
      </div>
      <div class="card">
        <h3><span class="stat-ico blue">@include('partials.icon', ['name' => 'box', 'size' => 15])</span> Packages</h3>
        <div class="big">{{ $packageCount }}</div>
        <div class="sub">hosting plans (cPanel-compatible limits)</div>
        @unless ($packagesReady)
          <div class="sub muted" style="margin-top:8px">packages table arrives with Step S4</div>
        @endunless
      </div>
      <div class="card">
        <h3><span class="stat-ico teal">@include('partials.icon', ['name' => 'memory', 'size' => 15])</span> Memory</h3>
        @if ($sysinfo)
          <div class="big">{{ $sysinfo['memory']['used_pct'] }}%</div>
          <div class="sub">{{ $sysinfo['memory']['used_mb'] }} / {{ $sysinfo['memory']['total_mb'] }} MB</div>
          <div class="bar blue {{ $sysinfo['memory']['used_pct'] > 85 ? 'bad' : ($sysinfo['memory']['used_pct'] > 70 ? 'warn' : '') }}" style="margin-top:10px">
            <span style="width: {{ min(100, $sysinfo['memory']['used_pct']) }}%"></span>
          </div>
        @else
          <div class="big muted">—</div><div class="sub">waiting for agent…</div>
        @endif
      </div>
      <div class="card">
        <h3><span class="stat-ico blue">@include('partials.icon', ['name' => 'hdd', 'size' => 15])</span> Disk (/)</h3>
        @if ($sysinfo)
          <div class="big">{{ $sysinfo['disk']['used_pct'] }}%</div>
          <div class="sub">{{ $sysinfo['disk']['used_gb'] }} / {{ $sysinfo['disk']['total_gb'] }} GB</div>
          <div class="bar blue {{ $sysinfo['disk']['used_pct'] > 85 ? 'bad' : ($sysinfo['disk']['used_pct'] > 70 ? 'warn' : '') }}" style="margin-top:10px">
            <span style="width: {{ min(100, $sysinfo['disk']['used_pct']) }}%"></span>
          </div>
        @else
          <div class="big muted">—</div><div class="sub">waiting for agent…</div>
        @endif
      </div>
    </div>

    {{-- ===== Server information (WHM panel) ===== --}}
    <div class="section">
      @include('partials.icon', ['name' => 'server', 'size' => 20])
      <h2>Server information</h2>
      <span class="rule"></span>
      <span class="small muted">parity #182 · live from paneld</span>
    </div>
    <div class="card">
      @if ($sysinfo)
        @php
          $up = (int) ($sysinfo['uptime_sec'] ?? 0);
          $uptime = sprintf('%d days, %d hours, %d min', intdiv($up, 86400), intdiv($up % 86400, 3600), intdiv($up % 3600, 60));
        @endphp
        <div class="kv"><span class="k">Hostname</span><span class="v">{{ $sysinfo['hostname'] }}</span></div>
        <div class="kv"><span class="k">Operating system</span><span class="v">{{ $sysinfo['os'] }} ({{ $sysinfo['arch'] }})</span></div>
        <div class="kv"><span class="k">Kernel</span><span class="v">{{ $sysinfo['kernel'] ?? '—' }}</span></div>
        <div class="kv"><span class="k">Panel version</span><span class="v">AlphaCP {{ getenv('ACP_PANEL_VERSION') ?: '0.3.0' }} · PHP {{ $sysinfo['php_version'] }}</span></div>
        <div class="kv"><span class="k">CPU</span><span class="v">{{ $sysinfo['cpu_cores'] }} cores · load {{ $sysinfo['load'][0] }} / {{ $sysinfo['load'][1] }} / {{ $sysinfo['load'][2] }}</span></div>
        <div class="kv"><span class="k">Uptime</span><span class="v">{{ $uptime }}</span></div>
        <div class="kv"><span class="k">Server</span><span class="v">{{ $serverName }}</span></div>
      @else
        <div class="sub">Server information is arriving from the agent… <span class="muted">(1–2s if paneld is running)</span></div>
      @endif
    </div>

    {{-- ===== Accounts (parity #105) ===== --}}
    <div class="section">
      @include('partials.icon', ['name' => 'users', 'size' => 20])
      <h2>Accounts</h2>
      <span class="rule"></span>
      <span class="small muted">parity #105 · List Accounts</span>
    </div>
    <div class="card" style="padding:8px 10px">
      <div class="stat-chips">
        <span class="chip">total <b>{{ $accountStats['total'] }}</b></span>
        <span class="chip"><span class="dot"></span> active <b>{{ $accountStats['active'] }}</b></span>
        <span class="chip"><span class="dot warn"></span> suspended <b>{{ $accountStats['suspended'] }}</b></span>
        <span class="chip"><span class="dot dim"></span> pending <b>{{ $accountStats['pending'] }}</b></span>
      </div>
      @if ($accounts->isNotEmpty())
        <table class="acct">
          <thead><tr><th>Username</th><th>Domain</th><th>Package</th><th>Disk</th><th>Status</th></tr></thead>
          <tbody>
          @foreach ($accounts as $acc)
            <tr>
              <td><code>{{ $acc->username }}</code></td>
              <td>{{ $acc->main_domain }}</td>
              <td class="muted">{{ $acc->package_name ?? '—' }}</td>
              <td class="muted">{{ number_format((float) $acc->disk_used_mb) }} MB</td>
              <td>
                <span class="pill {{ $acc->status === 'active' ? 'on' : ($acc->status === 'suspended' ? 'err' : 'off') }}">{{ $acc->status }}</span>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @else
        <div class="sub" style="padding:12px">
          @if ($accountsReady)
            No accounts yet — <b>Create a New Account</b> (parity #104, Step S3) provisions the first one.
          @else
            Accounts table arrives with the Step S3 migration — the panel stays green until then.
          @endif
        </div>
      @endif
    </div>

    {{-- ===== Services (parity #171) ===== --}}
    <div class="section">
      @include('partials.icon', ['name' => 'gauge', 'size' => 20])
      <h2>Service status</h2>
      <span class="rule"></span>
      <span class="small muted">parity #171 · Service Manager view</span>
    </div>
    <div class="card" style="padding:8px 10px">
      <p class="sub" style="margin:6px 4px 8px"><span class="dot {{ $activeCount > 0 ? '' : 'dim' }}"></span> <b style="color:var(--ink)">{{ $activeCount }}</b> active of {{ count($services) }} known services</p>
      @if ($services)
        <table class="svc">
          <thead><tr><th>Service</th><th>State</th><th>Boot</th></tr></thead>
          <tbody>
          @foreach ($services as $name => $state)
            <tr>
              <td><code>{{ $name }}</code></td>
              <td>
                @php $act = $state['active'] ?? 'unknown'; @endphp
                <span class="pill {{ $act === 'active' ? 'on' : ($act === 'failed' ? 'err' : 'off') }}">{{ $act }}</span>
              </td>
              <td class="muted small">{{ $state['enabled'] ?? '-' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @else
        <div class="sub" style="padding:12px">Service data is arriving from the agent…</div>
      @endif
    </div>
  </div>

  {{-- ===== right rail ===== --}}
  <aside class="dash-side">
    <div class="card">
      <h3>@include('partials.icon', ['name' => 'list', 'size' => 14]) Task queue</h3>
      <div class="big">{{ $queue['queued'] }}</div>
      <div class="sub">queued · {{ $queue['running'] }} running · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</div>
      @if ($queue['last'])
        <div class="sub" style="margin-top:10px">
          last: <code>#{{ $queue['last']->id }} {{ $queue['last']->type }}</code>
          → <span class="pill {{ $queue['last']->status === 'success' ? 'on' : ($queue['last']->status === 'failed' ? 'err' : '') }}">{{ $queue['last']->status }}</span>
        </div>
      @endif
    </div>

    <div class="card">
      <h3>@include('partials.icon', ['name' => 'star', 'size' => 14]) Quick links</h3>
      <div class="quick-links">
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'plus', 'size' => 15]) Create account</a>
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'box', 'size' => 15]) New package</a>
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'terminal', 'size' => 15]) Terminal</a>
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'image', 'size' => 15]) Theme manager</a>
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'refresh', 'size' => 15]) Restart services</a>
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'archive', 'size' => 15]) Backup config</a>
      </div>
    </div>

    <div class="card">
      <h3>@include('partials.icon', ['name' => 'clock', 'size' => 14]) Recent activity</h3>
      <div class="activity">
        @forelse ($events as $event)
          <div class="row">
            <code>{{ $event->action }}</code>
            <span class="pill {{ $event->severity === 'warning' ? 'err' : '' }}">{{ $event->severity }}</span>
            <span class="when">{{ $event->created_at }}</span>
          </div>
        @empty
          <div class="sub">No activity yet.</div>
        @endforelse
      </div>
    </div>
  </aside>
</div>
@endsection
