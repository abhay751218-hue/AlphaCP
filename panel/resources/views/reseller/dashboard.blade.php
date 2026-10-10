@extends('layouts.whm')

@section('title', 'Dashboard')

@section('content')
<div class="page-head">
  <h1>Dashboard</h1>
  <span class="badge-whm">RESELLER</span>
  <span class="sub">your accounts · your packages · reseller scope</span>
  @if ($sysState !== 'success')
    <span class="chip"><span class="dot warn"></span> agent {{ $sysState }}</span>
  @endif
</div>

<div class="dash-grid">
  <div class="dash-main">
    {{-- ===== stat cards ===== --}}
    <div class="grid cols-4">
      <div class="card">
        <h3><span class="stat-ico">@include('partials.icon', ['name' => 'users', 'size' => 15])</span> My accounts</h3>
        <div class="big">{{ $accountStats['active'] }}<span class="sub" style="font-size:14px"> / {{ $accountStats['total'] }}</span></div>
        <div class="sub">{{ $accountStats['suspended'] }} suspended · {{ $accountStats['pending'] }} pending</div>
        @unless ($accountsReady)
          <div class="sub muted" style="margin-top:8px">accounts table arrives with Step S3</div>
        @endunless
      </div>
      <div class="card">
        <h3><span class="stat-ico blue">@include('partials.icon', ['name' => 'box', 'size' => 15])</span> Packages</h3>
        <div class="big">{{ $packageCount }}</div>
        <div class="sub">plans available to you</div>
        @unless ($packagesReady)
          <div class="sub muted" style="margin-top:8px">packages table arrives with Step S4</div>
        @endunless
      </div>
      <div class="card">
        <h3><span class="stat-ico teal">@include('partials.icon', ['name' => 'memory', 'size' => 15])</span> Memory</h3>
        @if ($sysinfo)
          <div class="big">{{ $sysinfo['memory']['used_pct'] }}%</div>
          <div class="sub">{{ $sysinfo['memory']['used_mb'] }} / {{ $sysinfo['memory']['total_mb'] }} MB</div>
          <div class="bar teal {{ $sysinfo['memory']['used_pct'] > 85 ? 'bad' : ($sysinfo['memory']['used_pct'] > 70 ? 'warn' : '') }}" style="margin-top:10px">
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
          <div class="bar teal {{ $sysinfo['disk']['used_pct'] > 85 ? 'bad' : ($sysinfo['disk']['used_pct'] > 70 ? 'warn' : '') }}" style="margin-top:10px">
            <span style="width: {{ min(100, $sysinfo['disk']['used_pct']) }}%"></span>
          </div>
        @else
          <div class="big muted">—</div><div class="sub">waiting for agent…</div>
        @endif
      </div>
    </div>

    {{-- ===== My accounts (parity #105, reseller scope) ===== --}}
    <div class="section">
      @include('partials.icon', ['name' => 'users', 'size' => 20])
      <h2>My accounts</h2>
      <span class="rule"></span>
      <span class="small muted">parity #105 · List Accounts (reseller scope)</span>
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
            No accounts under your reseller hierarchy yet — <b>Create a New Account</b> (parity #104, Step S3).
          @else
            Accounts table arrives with the Step S3 migration — the panel stays green until then.
          @endif
        </div>
      @endif
    </div>

    {{-- ===== Account information ===== --}}
    <div class="section">
      @include('partials.icon', ['name' => 'chart', 'size' => 20])
      <h2>Usage overview</h2>
      <span class="rule"></span>
      <span class="small muted">parity #120–121 · bandwidth & quota</span>
    </div>
    <div class="card">
      @if ($accounts->isNotEmpty())
        @php
          $totalDisk = (float) $accounts->sum('disk_used_mb');
          $totalBw = (float) $accounts->sum('bw_used_mb');
        @endphp
        <div class="kv"><span class="k">Disk used (listed accounts)</span><span class="v">{{ number_format($totalDisk) }} MB</span></div>
        <div class="kv"><span class="k">Bandwidth used (listed accounts)</span><span class="v">{{ number_format($totalBw) }} MB</span></div>
        <div class="kv"><span class="k">Accounts shown</span><span class="v">{{ $accounts->count() }} of {{ $accountStats['total'] }}</span></div>
      @else
        <div class="sub">Usage rows appear here once accounts exist (Step S3). Bandwidth tracking lands with Step S11 (parity #121).</div>
      @endif
    </div>
  </div>

  {{-- ===== right rail ===== --}}
  <aside class="dash-side">
    <div class="card">
      <h3>@include('partials.icon', ['name' => 'list', 'size' => 14]) Task queue</h3>
      <div class="big">{{ $queue['queued'] }}</div>
      <div class="sub">queued · {{ $queue['running'] }} running · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</div>
    </div>

    <div class="card">
      <h3>@include('partials.icon', ['name' => 'star', 'size' => 14]) Quick links</h3>
      <div class="quick-links">
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'plus', 'size' => 15]) Create account</a>
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'box', 'size' => 15]) My packages</a>
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'key', 'size' => 15]) Reset password</a>
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'send', 'size' => 15]) Email my users</a>
        <a href="{{ route('coming.soon') }}">@include('partials.icon', ['name' => 'list', 'size' => 15]) Audit logs</a>
        <a href="{{ route('dashboard') }}">@include('partials.icon', ['name' => 'globe', 'size' => 15]) Client panel</a>
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
