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
        <a href="{{ route('dashboard') }}" title="Home" style="display:flex;align-items:center;gap:10px;color:inherit;text-decoration:none">
        <span class="logo">A</span>
        <span>
            {{ config('acp.brand.name', 'AlphaCP') }}
            <small>{{ ($panelMode ?? 'cpanel') === 'whm' ? 'Server Manager' : 'Account Panel' }} · {{ config('acp.version') }}</small>
        </span>
        </a>
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
    <a class="crumb" href="{{ route('dashboard') }}" title="Home — dashboard par wapas" style="text-decoration:none;flex:0 0 auto">🏠</a>
    <span class="crumb">@yield('title', 'Dashboard')</span>
    <span class="spacer"></span>
    <input type="search" id="acp-search" class="searchbox" placeholder="Find functions quickly by typing here (/)" autocomplete="off" aria-label="Search tools">
    <button class="nav-toggle" id="acp-dark-toggle" type="button" aria-label="Toggle dark mode" title="Dark mode">🌙</button>
    <details class="notif">
        <summary aria-label="Notifications" title="Notifications">🔔</summary>
        <div class="menu-pop">
            <p class="help" style="margin:0 0 4px">Koi nayi notification nahi.</p>
            @can('audit.view')<a href="{{ route('audit.index') }}">Audit log dekho →</a>@endcan
        </div>
    </details>
    <details class="user-menu">
        <summary>
            <span class="avatar">{{ strtoupper(substr(auth()->user()?->username ?? 'A', 0, 1)) }}</span>
            <span class="uname">{{ auth()->user()?->username ?? 'Guest' }}<small>{{ auth()->user()?->role?->label ?? 'user' }}</small></span>
            <span class="caret">▾</span>
        </summary>
        <div class="menu-pop">
            <a href="{{ route('security.password') }}">Password &amp; Security</a>
            <a href="{{ route('security.index') }}">Two-Factor (2FA)</a>
            <a href="{{ route('security.sessions') }}">Active Sessions</a>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Log out</button>
            </form>
        </div>
    </details>
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
    {{ config('acp.brand.name', 'AlphaCP') }} {{ config('acp.version') }} — {{ config('acp.brand.tagline', 'Hosting control panel') }}
</footer>
</div>


<script src="{{ asset('assets/panel.js') }}?v={{ @filemtime(public_path('assets/panel.js')) ?: config('acp.version') }}" defer></script>
</body>
</html>
