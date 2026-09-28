@extends('layouts.panel')

@section('title', 'Dashboard')

@section('content')
  <div class="section" style="margin-top:4px">
    <h2>Server health</h2>
    <span class="rule"></span>
    @if ($sysState !== 'success')
      <span class="chip"><span class="dot warn"></span> agent {{ $sysState }}</span>
    @endif
  </div>

  <div class="grid cols-4">
    {{-- memory --}}
    <div class="card">
      <h3>Memory</h3>
      @if ($sysinfo)
        <div class="big">{{ $sysinfo['memory']['used_pct'] }}%</div>
        <div class="sub">{{ $sysinfo['memory']['used_mb'] }} MB / {{ $sysinfo['memory']['total_mb'] }} MB used</div>
        <div class="bar {{ $sysinfo['memory']['used_pct'] > 85 ? 'bad' : ($sysinfo['memory']['used_pct'] > 70 ? 'warn' : '') }}" style="margin-top:10px">
          <span style="width: {{ min(100, $sysinfo['memory']['used_pct']) }}%"></span>
        </div>
        <div class="sub" style="margin-top:8px">swap {{ $sysinfo['swap']['used_mb'] }} / {{ $sysinfo['swap']['total_mb'] }} MB</div>
      @else
        <div class="big muted">—</div><div class="sub">agent ka intezaar…</div>
      @endif
    </div>

    {{-- disk --}}
    <div class="card">
      <h3>Disk (/)</h3>
      @if ($sysinfo)
        <div class="big">{{ $sysinfo['disk']['used_pct'] }}%</div>
        <div class="sub">{{ $sysinfo['disk']['used_gb'] }} GB / {{ $sysinfo['disk']['total_gb'] }} GB · {{ $sysinfo['disk']['free_gb'] }} GB free</div>
        <div class="bar {{ $sysinfo['disk']['used_pct'] > 85 ? 'bad' : '' }}" style="margin-top:10px">
          <span style="width: {{ min(100, $sysinfo['disk']['used_pct']) }}%"></span>
        </div>
      @else
        <div class="big muted">—</div><div class="sub">agent ka intezaar…</div>
      @endif
    </div>

    {{-- load / cpu --}}
    <div class="card">
      <h3>CPU load</h3>
      @if ($sysinfo)
        <div class="big">{{ $sysinfo['load'][0] }}</div>
        <div class="sub">{{ $sysinfo['cpu_cores'] }} cores · 5m {{ $sysinfo['load'][1] }} · 15m {{ $sysinfo['load'][2] }}</div>
        <div class="sub" style="margin-top:10px">
          {{ $sysinfo['hostname'] }} · {{ $sysinfo['arch'] }} · PHP {{ $sysinfo['php_version'] }}
        </div>
      @else
        <div class="big muted">—</div><div class="sub">agent ka intezaar…</div>
      @endif
    </div>

    {{-- queue --}}
    <div class="card">
      <h3>Task queue (paneld)</h3>
      <div class="big">{{ $queue['queued'] }}</div>
      <div class="sub">queued · {{ $queue['running'] }} running · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</div>
      @if ($queue['last'])
        <div class="sub" style="margin-top:10px">
          last: #{{ $queue['last']->id }} {{ $queue['last']->type }}
          → {{ $queue['last']->status }}@if($queue['last']->duration_ms) ({{ $queue['last']->duration_ms }}ms)@endif
        </div>
      @endif
    </div>
  </div>

  <div class="section">
    <h2>Services</h2>
    <span class="rule"></span>
    <span class="chip"><span class="dot {{ $activeCount > 0 ? '' : 'dim' }}"></span> <b>{{ $activeCount }}</b> active</span>
  </div>

  <div class="card" style="padding:8px 10px">
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
      <div class="sub" style="padding:12px">Agent se services ka data aa raha hai… <span class="muted">(paneld chal raha ho to 1-2 second me aa jayega)</span></div>
    @endif
  </div>

  <div class="section">
    <h2>Tools</h2>
    <span class="rule"></span>
    <span class="small muted">cPanel layout · har tile apne step ke saath aayega</span>
  </div>

  @foreach ($modules as $section)
    <div class="section" id="{{ $section['key'] }}" style="margin-top:18px">
      <h2 style="font-size:15px">
        {{ $section['name'] }}
      </h2>
      <span class="rule"></span>
      <span class="small muted">{{ count($section['tiles']) }} tools</span>
    </div>
    <div class="tiles">
      @foreach ($section['tiles'] as $tile)
        @php
          $name = $tile[0]; $step = $tile[1]; $live = $tile['live'] ?? false;
        @endphp
        <a class="tile {{ $live ? 'live' : '' }}" href="{{ route('coming.soon') }}">
          <span class="tico">
            @switch($section['icon'])
              @case('folder') 📁 @break
              @case('mail') ✉️ @break
              @case('globe') 🌐 @break
              @case('database') 🗄 @break
              @case('chart') 📊 @break
              @case('shield') 🛡 @break
              @case('box') 📦 @break
              @case('sliders') 🎛 @break
              @default 👤
            @endswitch
          </span>
          <span>
            <span class="tname">{{ $name }}</span><br>
            <span class="tstep">{{ $live ? 'active' : 'aayega: ' . $step }}</span>
          </span>
        </a>
      @endforeach
    </div>
  @endforeach

  <div class="section">
    <h2>Recent activity</h2>
    <span class="rule"></span>
    <span class="small muted">audit trail (immutable)</span>
  </div>
  <div class="card">
    @forelse ($events as $event)
      <div class="kv">
        <span><code>{{ $event->action }}</code> <span class="pill {{ $event->severity === 'warning' ? 'err' : '' }}">{{ $event->severity }}</span></span>
        <span class="muted small">
          {{ $event->actor_type }}{{ $event->actor_id ? '#' . $event->actor_id : '' }} ·
          {{ $event->created_at }}
        </span>
      </div>
    @empty
      <div class="sub">Abhi koi activity nahi.</div>
    @endforelse
  </div>

  <div class="section">
    <h2>About this panel</h2>
    <span class="rule"></span>
  </div>
  <div class="card">
    <div class="kv"><span>Panel version</span><span>{{ getenv('ACP_PANEL_VERSION') ?: '0.3.0' }} (Step 2B-1)</span></div>
    <div class="kv"><span>Installer version</span><span>{{ getenv('ACP_INSTALLER_VERSION') ?: 'unknown' }}</span></div>
    <div class="kv"><span>Agent</span><span>paneld (root task agent) — ADR-0002</span></div>
    <div class="kv"><span>Parity contract</span><span><code>docs/09-cpanel-parity-checklist.md</code></span></div>
  </div>
@endsection
