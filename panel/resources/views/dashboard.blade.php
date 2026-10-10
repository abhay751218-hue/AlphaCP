@extends('layouts.panel')

@section('title', 'Dashboard')

@section('content')
<div class="page-head">
  <h1>Dashboard</h1>
  <span class="sub">AlphaCP · Paper Lantern theme · cPanel-parity layout</span>
  @if ($sysState !== 'success')
    <span class="chip"><span class="dot warn"></span> agent {{ $sysState }}</span>
  @endif
</div>

<div class="dash-grid">
  <div class="dash-main">
    {{-- ===== Statistics (cPanel right-rail style, shown as top cards) ===== --}}
    <div class="section" style="margin-top:2px">
      @include('partials.icon', ['name' => 'gauge', 'size' => 20])
      <h2>Statistics</h2>
      <span class="rule"></span>
      <span class="small muted">live from paneld</span>
    </div>

    <div class="grid cols-4">
      {{-- memory --}}
      <div class="card">
        <h3><span class="stat-ico">@include('partials.icon', ['name' => 'memory', 'size' => 15])</span> Memory</h3>
        @if ($sysinfo)
          <div class="big">{{ $sysinfo['memory']['used_pct'] }}%</div>
          <div class="sub">{{ $sysinfo['memory']['used_mb'] }} MB / {{ $sysinfo['memory']['total_mb'] }} MB used</div>
          <div class="bar blue {{ $sysinfo['memory']['used_pct'] > 85 ? 'bad' : ($sysinfo['memory']['used_pct'] > 70 ? 'warn' : '') }}" style="margin-top:10px">
            <span style="width: {{ min(100, $sysinfo['memory']['used_pct']) }}%"></span>
          </div>
          <div class="sub" style="margin-top:8px">swap {{ $sysinfo['swap']['used_mb'] }} / {{ $sysinfo['swap']['total_mb'] }} MB</div>
        @else
          <div class="big muted">—</div><div class="sub">waiting for agent…</div>
        @endif
      </div>

      {{-- disk --}}
      <div class="card">
        <h3><span class="stat-ico blue">@include('partials.icon', ['name' => 'hdd', 'size' => 15])</span> Disk (/)</h3>
        @if ($sysinfo)
          <div class="big">{{ $sysinfo['disk']['used_pct'] }}%</div>
          <div class="sub">{{ $sysinfo['disk']['used_gb'] }} GB / {{ $sysinfo['disk']['total_gb'] }} GB · {{ $sysinfo['disk']['free_gb'] }} GB free</div>
          <div class="bar blue {{ $sysinfo['disk']['used_pct'] > 85 ? 'bad' : ($sysinfo['disk']['used_pct'] > 70 ? 'warn' : '') }}" style="margin-top:10px">
            <span style="width: {{ min(100, $sysinfo['disk']['used_pct']) }}%"></span>
          </div>
        @else
          <div class="big muted">—</div><div class="sub">waiting for agent…</div>
        @endif
      </div>

      {{-- load / cpu --}}
      <div class="card">
        <h3><span class="stat-ico teal">@include('partials.icon', ['name' => 'cpu', 'size' => 15])</span> CPU load</h3>
        @if ($sysinfo)
          <div class="big">{{ $sysinfo['load'][0] }}</div>
          <div class="sub">{{ $sysinfo['cpu_cores'] }} cores · 5m {{ $sysinfo['load'][1] }} · 15m {{ $sysinfo['load'][2] }}</div>
          <div class="sub" style="margin-top:10px">{{ $sysinfo['hostname'] }} · {{ $sysinfo['arch'] }} · PHP {{ $sysinfo['php_version'] }}</div>
        @else
          <div class="big muted">—</div><div class="sub">waiting for agent…</div>
        @endif
      </div>

      {{-- queue --}}
      <div class="card">
        <h3><span class="stat-ico green">@include('partials.icon', ['name' => 'list', 'size' => 15])</span> Task queue</h3>
        <div class="big">{{ $queue['queued'] }}</div>
        <div class="sub">queued · {{ $queue['running'] }} running · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</div>
        @if ($queue['last'])
          <div class="sub" style="margin-top:10px">
            last: <code>#{{ $queue['last']->id }} {{ $queue['last']->type }}</code>
            → <span class="pill {{ $queue['last']->status === 'success' ? 'on' : ($queue['last']->status === 'failed' ? 'err' : '') }}">{{ $queue['last']->status }}</span>
            @if($queue['last']->duration_ms) ({{ $queue['last']->duration_ms }}ms)@endif
          </div>
        @endif
      </div>
    </div>

    {{-- ===== Tools icon grid (cPanel Paper Lantern groups) ===== --}}
    <div class="section">
      @include('partials.icon', ['name' => 'box', 'size' => 20])
      <h2>Tools</h2>
      <span class="rule"></span>
      <span class="small muted">search with the box above · every tile carries its roadmap step</span>
    </div>

    @foreach ($modules as $section)
      <div data-section>
        <div class="section" id="{{ $section['key'] }}" style="--sec: {{ $section['color'] ?? '#64748b' }}">
          <span class="sec-ico">@include('partials.icon', ['name' => $section['icon'], 'size' => 15])</span>
          <h2>{{ $section['name'] }}</h2>
          <span class="rule"></span>
          <span class="small muted">{{ count($section['tiles']) }} tools</span>
        </div>
        <div class="tiles">
          @foreach ($section['tiles'] as $tile)
            @php
              $name = $tile[0]; $step = $tile[1]; $live = $tile['live'] ?? false;
              $ticon = $tile['icon'] ?? $section['icon'];
            @endphp
            <a class="tile {{ $live ? 'live' : '' }}" href="{{ route('coming.soon') }}" data-tool style="--sec: {{ $section['color'] ?? '#64748b' }}">
              <span class="tico">@include('partials.icon', ['name' => $ticon, 'size' => 18])</span>
              <span>
                <span class="tname">{{ $name }}</span>
                <span class="tstep">{{ $live ? 'Active now' : 'Roadmap ' . $step }}</span>
              </span>
            </a>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>

  {{-- ===== Right rail: General information / Services / Recent activity ===== --}}
  <aside class="dash-side">
    <div class="card">
      <h3>@include('partials.icon', ['name' => 'info', 'size' => 14]) General information</h3>
      <div class="kv"><span class="k">Current user</span><span class="v">{{ auth()->user()->username ?? '-' }}</span></div>
      <div class="kv"><span class="k">Server</span><span class="v">{{ $serverName }}</span></div>
      <div class="kv"><span class="k">Home directory</span><span class="v">/home/{{ auth()->user()->username ?? 'user' }}</span></div>
      <div class="kv"><span class="k">Last login IP</span><span class="v">{{ request()->ip() }}</span></div>
      <div class="kv"><span class="k">Panel version</span><span class="v">{{ getenv('ACP_PANEL_VERSION') ?: '0.3.0' }}</span></div>
      <div class="kv"><span class="k">Theme</span><span class="v"><span class="dot" style="display:inline-block;vertical-align:0;margin-right:5px;background:var(--cp-orange);box-shadow:0 0 0 3px rgba(255,108,44,.18)"></span>Paper Lantern</span></div>
      <div class="kv"><span class="k">Agent</span><span class="v">paneld · <b style="color:{{ ($queue['running'] ?? 0) > 0 ? 'var(--amber)' : 'var(--green)' }}">{{ ($queue['running'] ?? 0) > 0 ? 'busy' : 'idle' }}</b></span></div>
    </div>

    <div class="card">
      <h3>@include('partials.icon', ['name' => 'server', 'size' => 14]) Services</h3>
      <p class="sub" style="margin:0 0 8px"><span class="dot {{ $activeCount > 0 ? '' : 'dim' }}"></span> <b style="color:var(--ink)">{{ $activeCount }}</b> active</p>
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
        <div class="sub">Service data is arriving from the agent… <span class="muted">(1–2s if paneld is running)</span></div>
      @endif
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
      <p class="sub muted small" style="margin:10px 0 0">Immutable audit trail · <code>docs/03-security-matrix.md</code></p>
    </div>
  </aside>
</div>
@endsection
