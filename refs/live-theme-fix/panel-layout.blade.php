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
<body class="mode-{{ ($panelMode ?? 'cpanel') === 'whm' ? 'whm' : 'cpanel' }}">
<div class="nav-backdrop" aria-hidden="true"></div>

<aside class="sidenav" id="acp-side">
    <div class="side-brand">
        <span class="logo">A</span>
        <span>
            {{ config('acp.brand.name', 'AlphaCP') }}
            <small>{{ ($panelMode ?? 'cpanel') === 'whm' ? 'WHM · Server Manager' : 'cPanel · Account Panel' }} · {{ config('acp.version') }}</small>
        </span>
    </div>
    @if (($panelMode ?? 'cpanel') === 'whm')
        @include('partials.whm-sidebar', [])
    @else
        @include('partials.cpanel-sidebar', [])
    @endif
</aside>

<div class="main-col">
<header class="mainbar">
    <button class="nav-toggle" id="acp-nav-toggle" type="button" aria-label="Menu" aria-expanded="false">☰</button>
    <span class="crumb">@yield('title', 'Dashboard')</span>
    <span class="spacer"></span>
    <input type="search" id="acp-search" class="searchbox" placeholder="Search Tools (/)" autocomplete="off" aria-label="Search tools">
    <span class="user">
        <span class="avatar">{{ strtoupper(substr(auth()->user()?->username ?? 'A', 0, 1)) }}</span>
        <span class="uname">{{ auth()->user()?->username ?? 'Guest' }}<small>{{ auth()->user()?->role?->label ?? 'user' }}</small></span>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="btn small secondary" type="submit">Logout</button>
        </form>
    </span>
</header>

<main class="wrap main-main">
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
    {{ config('acp.brand.name', 'AlphaCP') }} {{ config('acp.version') }} — {{ config('acp.brand.tagline', 'Hosting control panel') }} ·
    parity checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span>
</footer>
</div>

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
/* P-UI-5.2: mobile hamburger — capture-phase delegation (bulletproof).
   #acp-nav-toggle tap -> body.nav-open toggle; sidebar ke bahar tap / Escape /
   backdrop tap -> close. Capture phase isliye ki koi aur handler
   stopPropagation() kar de to bhi ye hamesha chale. */
(function () {
    function isOpen() { return document.body.classList.contains('nav-open'); }
    function setOpen(open) {
        document.body.classList.toggle('nav-open', open);
        var b = document.getElementById('acp-nav-toggle');
        if (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('#acp-nav-toggle') : null;
        if (t) { e.preventDefault(); setOpen(!isOpen()); return; }
        if (!isOpen()) { return; }
        if (e.target.closest && e.target.closest('.sidenav')) { return; }
        setOpen(false);
    }, true);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) { setOpen(false); }
    });
    /* sidebar link tap -> drawer band (mobile UX) */
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('.sidenav a[href]') : null;
        if (a && isOpen()) { setOpen(false); }
    });
    /* collapsible section cards */
    document.addEventListener('click', function (e) {
        var c = e.target.closest ? e.target.closest('.sect-head .chev') : null;
        if (c && c.closest('.sect-card')) { c.closest('.sect-card').classList.toggle('collapsed'); }
    });
})();
</script>
</body>
</html>
