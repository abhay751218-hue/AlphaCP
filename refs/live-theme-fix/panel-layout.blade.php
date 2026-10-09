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


<script src="{{ asset('assets/panel.js') }}?v={{ @filemtime(public_path('assets/panel.js')) ?: config('acp.version') }}" defer></script>
</body>
</html>
