{{-- REBRAND_DONE --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · {{ config('acp.brand.name', 'AlphaCP') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ @filemtime(public_path('assets/panel.css')) ?: config('acp.version') }}">
</head>
<body>

<header class="topbar">
    <div class="brand">
        <span class="logo">A</span>
        <span>
            {{ config('acp.brand.name', 'AlphaCP') }} {{ ($panelMode ?? 'cpanel') === 'whm' ? 'Server Manager' : '' }}
            <small>{{ ($panelMode ?? 'cpanel') === 'whm' ? 'Server Manager' : 'Account Panel' }} · {{ config('acp.version') }}</small>
        </span>
    </div>

    <button class="nav-toggle" id="acp-nav-toggle" type="button" aria-label="Menu" aria-expanded="false">☰</button>
    <nav class="topnav" id="acp-topnav" aria-label="Main">
        <a href="{{ route('dashboard') }}">Dashboard</a>
        @if (($panelMode ?? 'cpanel') === 'whm')
            @can('accounts.view')<a href="{{ route('accounts.index') }}">Accounts</a>@endcan
            @can('packages.view')<a href="{{ route('packages.index') }}">Packages</a>@endcan
            @can('users.view')<a href="{{ route('users.index') }}">Users</a>@endcan
            @can('system.view')<a href="{{ route('system.index') }}">System</a>@endcan
        @else
            @can('domains.view')<a href="{{ route('domains.index') }}">Domains</a>@endcan
            @can('software.view')<a href="{{ route('php.index') }}">MultiPHP</a>@endcan
            @can('cron.view')<a href="{{ route('cron.index') }}">Cron</a>@endcan
            @can('ssl.view')<a href="{{ route('ssl.index') }}">SSL</a>@endcan
            <a href="{{ route('security.index') }}">Security</a>
        @endif
    </nav>

    <input type="search" id="acp-search" class="searchbox" placeholder="Search tools…" autocomplete="off" aria-label="Search tools">

    <span class="spacer"></span>

    <div class="meta">
        server: <strong>{{ $server['hostname'] ?? 'unknown' }}</strong><br>
        panel {{ config('acp.version') }} · agent {{ config('acp.agent_version') }}
    </div>

    <div class="user">
        <span class="avatar">{{ strtoupper(substr(auth()->user()?->username ?? 'A', 0, 1)) }}</span>
        <span class="meta">
            {{ auth()->user()?->username ?? 'Guest' }}<br>
            <span class="muted">{{ auth()->user()?->role?->label ?? 'user' }}</span>
        </span>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="btn small secondary" type="submit">Logout</button>
        </form>
    </div>
</header>

@if (($panelMode ?? 'cpanel') === 'whm')
<div class="shell whm-shell">
    <aside class="side">
        @include('partials.whm-sidebar', [])
    </aside>
    <main class="wrap main-col">
@else
<div class="shell">
    <aside class="side">
        @include('partials.cpanel-sidebar', [])
    </aside>
    <main class="wrap main-col">
@endif
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
</div>

<footer class="wrap muted" style="padding-top:0; font-size:12.5px">
    {{ config('acp.brand.name', 'AlphaCP') }} {{ config('acp.version') }} — {{ config('acp.brand.tagline', 'Hosting control panel') }} ·
    parity checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span>
</footer>

<script>
/* cPanel-style top search: dashboard ke tool tiles live filter karta hai. */
(function () {
    var q = document.getElementById('acp-search');
    if (!q) { return; }
    q.addEventListener('input', function () {
        var v = q.value.trim().toLowerCase();
        document.querySelectorAll('.grid.tiles').forEach(function (grid) {
            var visible = 0;
            grid.querySelectorAll('.tile').forEach(function (tile) {
                var name = tile.querySelector('.name');
                var hit = v === '' || (name && name.textContent.toLowerCase().indexOf(v) !== -1);
                tile.classList.toggle('hidden', !hit);
                if (hit) { visible++; }
            });
            var head = grid.previousElementSibling;
            if (head && head.classList.contains('section-title')) {
                head.classList.toggle('hidden', visible === 0 && v !== '');
            }
        });
    });
})();
</script>
<script>
/* P-UI-5: mobile hamburger — topnav ko stack karta hai chhoti screens par. */
(function () {
    var b = document.getElementById('acp-nav-toggle');
    var n = document.getElementById('acp-topnav');
    if (!b || !n) { return; }
    b.addEventListener('click', function () {
        var open = n.classList.toggle('open');
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();
</script>
</body>
</html>
