<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title', 'Dashboard') · AlphaCP {{ $panelKind === 'reseller' ? 'Reseller' : 'WHM' }}</title>
  <link rel="stylesheet" href="{{ asset('css/panel.css') }}">
  <link rel="stylesheet" href="{{ asset('css/whm.css') }}">
</head>
<body class="whm {{ ($panelKind ?? 'admin') === 'reseller' ? 'reseller' : '' }}">
@include('partials.icons')

<header class="topbar">
  <div class="brand">
    <div class="mark">A</div>
    <div class="bname">AlphaCP<small>{{ $serverName ?? 'server' }}</small></div>
  </div>
  <span class="badge-whm">{{ ($panelKind ?? 'admin') === 'reseller' ? 'RESELLER' : 'WHM' }}</span>

  <div class="spacer"></div>

  <span class="chip hide-sm"><span class="dot {{ ($queue['failed'] ?? 0) > 0 ? 'warn' : '' }}"></span> agent <b>{{ ($queue['running'] ?? 0) > 0 ? 'busy' : 'idle' }}</b></span>
  <span class="chip hide-sm">queued <b>{{ $queue['queued'] ?? 0 }}</b></span>

  @if (auth()->user()->isAdmin())
    <a class="btn" href="{{ route('dashboard') }}" title="Client panel (cPanel)">
      @include('partials.icon', ['name' => 'globe', 'size' => 14])
      <span class="hide-sm">Client panel</span>
    </a>
  @endif

  <div class="userbox">
    <span class="avatar">{{ strtoupper(substr(auth()->user()->username ?? 'A', 0, 1)) }}</span>
    <span>
      <span class="uname">{{ auth()->user()->username ?? '-' }}</span><br>
      <span class="urole">{{ auth()->user()->roleSlug() }}</span>
    </span>
    <form method="post" action="{{ route('logout') }}">
      @csrf
      <button class="btn" type="submit" title="Logout">
        @include('partials.icon', ['name' => 'logout', 'size' => 15])
        <span class="hide-sm">Logout</span>
      </button>
    </form>
  </div>
</header>

<div class="shell">
  <nav class="sidebar" aria-label="WHM navigation">
    <div class="whm-search">
      @include('partials.icon', ['name' => 'search', 'size' => 15])
      <input id="whmSearch" type="search" placeholder="Search {{ ($panelKind ?? 'admin') === 'reseller' ? 'reseller panel' : 'WHM' }}…" autocomplete="off" aria-label="Search menu">
    </div>
    @foreach ($menu ?? [] as $section)
      <h4>@include('partials.icon', ['name' => $section['icon'], 'size' => 13]) {{ $section['name'] }}</h4>
      @foreach ($section['items'] as $item)
        @php
          $href = isset($item['route']) ? route($item['route']) : route('coming.soon');
          $isActive = isset($item['route']) && request()->routeIs($item['route']);
        @endphp
        <a href="{{ $href }}" class="{{ $isActive ? 'active' : '' }}" data-nav>
          @include('partials.icon', ['name' => $item['icon'], 'size' => 16])
          {{ $item['name'] }}
          @if (!empty($item['live']))
            <span class="live-dot" title="Live"><span class="dot"></span></span>
          @else
            <span class="parity">#{{ $item['parity'] ?? $item['step'] }}</span>
          @endif
        </a>
      @endforeach
    @endforeach
  </nav>

  <main class="content">
    @if (session('status'))
      <div class="flash ok">@include('partials.icon', ['name' => 'check', 'size' => 15]) {{ session('status') }}</div>
    @endif
    @if (session('error'))
      <div class="flash err">@include('partials.icon', ['name' => 'alert', 'size' => 15]) {{ session('error') }}</div>
    @endif
    @yield('content')
  </main>
</div>

<script>
/* WHM-style sidebar search: filters the categorized menu live (like real WHM). */
(function () {
  var input = document.getElementById('whmSearch');
  if (!input) return;
  var items = Array.prototype.slice.call(document.querySelectorAll('.sidebar a[data-nav]'));
  var heads = Array.prototype.slice.call(document.querySelectorAll('.sidebar h4'));
  function norm(s) { return (s || '').toLowerCase(); }
  input.addEventListener('input', function () {
    var q = norm(input.value).trim();
    items.forEach(function (a) {
      a.style.display = (!q || norm(a.textContent).indexOf(q) !== -1) ? '' : 'none';
    });
    /* hide section headers whose items are all hidden */
    heads.forEach(function (h) {
      var el = h.nextElementSibling, any = false;
      while (el && el.tagName !== 'H4') {
        if (el.tagName === 'A' && el.style.display !== 'none') { any = true; break; }
        el = el.nextElementSibling;
      }
      h.style.display = (q && !any) ? 'none' : '';
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== input && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
      e.preventDefault();
      input.focus();
    }
  });
})();
</script>
</body>
</html>
