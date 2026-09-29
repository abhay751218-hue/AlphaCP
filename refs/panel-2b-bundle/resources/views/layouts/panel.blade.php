<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · AlphaCP</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ config('acp.version') }}">
</head>
<body>

<header class="topbar">
    <div class="brand">
        <span class="logo">A</span>
        <span>
            AlphaCP {{ ($panelMode ?? 'cpanel') === 'whm' ? 'WHM' : '' }}
            <small>{{ ($panelMode ?? 'cpanel') === 'whm' ? 'Web Host Manager' : 'cPanel' }} · {{ config('acp.version') }}</small>
        </span>
    </div>

    <nav class="topnav" aria-label="Main">
        <a href="{{ route('dashboard') }}">Dashboard</a>
        @if (($panelMode ?? 'cpanel') === 'whm')
            @can('accounts.view')<a href="{{ route('accounts.index') }}">Accounts</a>@endcan
            @can('packages.view')<a href="{{ route('packages.index') }}">Packages</a>@endcan
            @can('users.view')<a href="{{ route('users.index') }}">Users</a>@endcan
            @can('system.view')<a href="{{ route('system.index') }}">System</a>@endcan
        @else
            @can('domains.view')<a href="{{ route('domains.index') }}">Domains</a>@endcan
            <a href="{{ route('security.index') }}">Security</a>
        @endif
    </nav>

    <span class="spacer"></span>

    <div class="meta">
        server: <strong>{{ $server['hostname'] ?? 'unknown' }}</strong><br>
        panel {{ config('acp.version') }} · agent {{ config('acp.agent_version') }}
    </div>

    <div class="user">
        <span class="avatar">{{ strtoupper(substr(auth()->user()->username, 0, 1)) }}</span>
        <span class="meta">
            {{ auth()->user()->username }}<br>
            <span class="muted">{{ auth()->user()->role?->label ?? 'user' }}</span>
        </span>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="btn small secondary" type="submit">Logout</button>
        </form>
    </div>
</header>

<main class="wrap">
    @include('partials.flash', [])

    <div class="page-head">
        <div>
            <h1>@yield('title', 'Dashboard')</h1>
            <p>@yield('subtitle', '')</p>
        </div>
        <div class="push row">
            @yield('actions')
        </div>
    </div>

    @yield('content')
</main>

<footer class="wrap muted" style="padding-top:0; font-size:12.5px">
    AlphaCP {{ config('acp.version') }} — cPanel-parity control panel ·
    parity checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span>
</footer>

</body>
</html>
