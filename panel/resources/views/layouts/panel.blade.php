<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title', 'Dashboard') · AlphaCP</title>
  <link rel="stylesheet" href="{{ asset('css/panel.css') }}">
</head>
<body>
@include('partials.icons')

<header class="topbar">
  <div class="brand">
    <div class="mark">A</div>
    <div class="bname">AlphaCP<small>{{ $serverName ?? 'server' }}</small></div>
  </div>

  <div class="tsearch">
    @include('partials.icon', ['name' => 'search', 'size' => 15])
    <input id="toolSearch" type="search" placeholder="Search tools…" autocomplete="off" aria-label="Search tools">
    <kbd>/</kbd>
  </div>

  <div class="spacer"></div>

  <span class="chip hide-sm"><span class="dot {{ ($queue['failed'] ?? 0) > 0 ? 'warn' : '' }}"></span> agent <b>{{ ($queue['running'] ?? 0) > 0 ? 'busy' : 'idle' }}</b></span>
  <span class="chip hide-sm">queued <b>{{ $queue['queued'] ?? 0 }}</b></span>

  <div class="userbox">
    <span class="avatar">{{ strtoupper(substr(auth()->user()->username ?? 'A', 0, 1)) }}</span>
    <span>
      <span class="uname">{{ auth()->user()->username ?? '-' }}</span><br>
      <span class="urole">{{ auth()->user()->role ?? 'admin' }}</span>
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
  <nav class="sidebar" aria-label="Panel navigation">
    <h4>Home</h4>
    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" data-nav>
      @include('partials.icon', ['name' => 'home', 'size' => 17])
      Dashboard
    </a>
    <h4>Tools (cPanel layout)</h4>
    @foreach ($modules ?? [] as $section)
      <a href="#{{ $section['key'] }}" data-nav style="--sec: {{ $section['color'] ?? '#64748b' }}">
        @include('partials.icon', ['name' => $section['icon'], 'size' => 17])
        {{ $section['name'] }}
        <span class="count">{{ count($section['tiles']) }}</span>
      </a>
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
/* cPanel-style tool search: filters sidebar nav + dashboard tiles live.
   Press "/" anywhere to jump into the search box. No dependencies. */
(function () {
  var input = document.getElementById('toolSearch');
  if (!input) return;
  var tiles = Array.prototype.slice.call(document.querySelectorAll('[data-tool]'));
  var navs = Array.prototype.slice.call(document.querySelectorAll('[data-nav]'));
  var sections = Array.prototype.slice.call(document.querySelectorAll('[data-section]'));
  var heads = Array.prototype.slice.call(document.querySelectorAll('.sidebar h4'));
  function norm(s) { return (s || '').toLowerCase(); }
  input.addEventListener('input', function () {
    var q = norm(input.value).trim();
    tiles.forEach(function (t) {
      t.style.display = (!q || norm(t.textContent).indexOf(q) !== -1) ? '' : 'none';
    });
    navs.forEach(function (n) {
      n.style.display = (!q || norm(n.textContent).indexOf(q) !== -1) ? '' : 'none';
    });
    sections.forEach(function (s) {
      var anyVisible = Array.prototype.some.call(
        s.querySelectorAll('[data-tool]'),
        function (t) { return t.style.display !== 'none'; }
      );
      s.style.display = anyVisible ? '' : 'none';
    });
    heads.forEach(function (h) { h.style.display = q ? 'none' : ''; });
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
