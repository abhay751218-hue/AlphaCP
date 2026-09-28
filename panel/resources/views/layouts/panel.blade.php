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
<header class="topbar">
  <div class="brand">
    <div class="mark">A</div>
    <div>AlphaCP<small>{{ $serverName ?? 'server' }}</small></div>
  </div>
  <div class="spacer"></div>
  <span class="chip"><span class="dot {{ ($queue['failed'] ?? 0) > 0 ? 'warn' : '' }}"></span> agent <b>{{ ($queue['running'] ?? 0) > 0 ? 'busy' : 'idle' }}</b></span>
  <span class="chip">queued <b>{{ $queue['queued'] ?? 0 }}</b></span>
  <span class="chip">login <b>{{ auth()->user()->username ?? '-' }}</b></span>
  <form method="post" action="{{ route('logout') }}">
    @csrf
    <button class="btn" type="submit">Logout</button>
  </form>
</header>

<div class="shell">
  <nav class="sidebar">
    <h4>Home</h4>
    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
      <span class="ico">▦</span> Dashboard
    </a>
    <h4>Tools (cPanel layout)</h4>
    @foreach ($modules ?? [] as $section)
      <a href="#{{ $section['key'] }}">
        <span class="ico">
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
        {{ $section['name'] }}
        <span class="count">{{ count($section['tiles']) }}</span>
      </a>
    @endforeach
  </nav>

  <main class="content">
    @if (session('status'))
      <div class="flash ok">{{ session('status') }}</div>
    @endif
    @if (session('error'))
      <div class="flash err">{{ session('error') }}</div>
    @endif
    @yield('content')
  </main>
</div>
</body>
</html>
