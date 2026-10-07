<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · AlphaCP</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ config('acp.version') }}">
</head>
<body data-panel-mode="{{ $panelMode ?? 'cpanel' }}">
@php
    $mode = $panelMode ?? 'cpanel';
    $workspaceName = match ($mode) {
        'whm' => 'Server Manager',
        'reseller' => 'Reseller Workspace',
        default => 'Hosting Account',
    };
@endphp

<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="topbar">
    <a class="brand" href="{{ route('dashboard') }}" aria-label="AlphaCP home">
        <span class="logo" aria-hidden="true">A</span>
        <span class="brand-copy">
            <span class="brand-name">AlphaCP</span>
            <small>{{ $workspaceName }} · {{ config('acp.version') }}</small>
        </span>
    </a>

    <span class="spacer"></span>

    @if ($mode !== 'cpanel')
        <div class="server-meta">
            <span class="server-dot" aria-hidden="true"></span>
            <span><span class="muted">Server</span><br><strong>{{ $server['hostname'] ?? 'unknown' }}</strong></span>
        </div>
    @elseif (isset($account) && $account)
        <div class="server-meta account-meta">
            <span class="server-dot" aria-hidden="true"></span>
            <span><span class="muted">Account</span><br><strong>{{ $account->main_domain }}</strong></span>
        </div>
    @endif

    <div class="user">
        <span class="avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()?->username ?? 'A', 0, 1)) }}</span>
        <span class="user-copy">
            <strong>{{ auth()->user()?->username ?? 'Guest' }}</strong><br>
            <span class="muted">{{ auth()->user()?->role?->label ?? 'user' }}</span>
        </span>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="btn small secondary" type="submit">Log out</button>
        </form>
    </div>
</header>

<div class="panel-shell">
    <aside class="sidebar" aria-label="{{ $workspaceName }} navigation">
        <div class="sidebar-heading">
            <span class="sidebar-eyebrow">Workspace</span>
            <strong>{{ $workspaceName }}</strong>
        </div>
        <nav class="side-nav" aria-label="Primary">
            <div class="nav-group">
                <span class="side-section-label">Overview</span>
                <a class="side-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>
                    <span class="nav-icon" aria-hidden="true">⌂</span><span>Dashboard</span>
                </a>
            </div>

            @if ($mode === 'whm')
                <div class="nav-group">
                    <span class="side-section-label">Hosting</span>
                    @can('accounts.view')
                        <a class="side-link {{ request()->routeIs('accounts.*') ? 'active' : '' }}" href="{{ route('accounts.index') }}" @if (request()->routeIs('accounts.*')) aria-current="page" @endif>
                            <span class="nav-icon" aria-hidden="true">◉</span><span>Accounts</span>
                        </a>
                    @endcan
                    @can('accounts.create')
                        <a class="side-link side-link-sub" href="{{ route('accounts.create') }}"><span class="nav-icon" aria-hidden="true">＋</span><span>Create account</span></a>
                    @endcan
                    @can('packages.view')
                        <a class="side-link {{ request()->routeIs('packages.*') ? 'active' : '' }}" href="{{ route('packages.index') }}" @if (request()->routeIs('packages.*')) aria-current="page" @endif>
                            <span class="nav-icon" aria-hidden="true">▦</span><span>Packages</span>
                        </a>
                    @endcan
                    @can('roles.manage')
                        <a class="side-link {{ request()->routeIs('resellers.*') ? 'active' : '' }}" href="{{ route('resellers.index') }}" @if (request()->routeIs('resellers.*')) aria-current="page" @endif>
                            <span class="nav-icon" aria-hidden="true">⇄</span><span>Reseller Center</span>
                        </a>
                    @endcan
                </div>
                <div class="nav-group">
                    <span class="side-section-label">Operations</span>
                    @can('dns.view')
                        <a class="side-link {{ request()->routeIs('dns-zones.*') ? 'active' : '' }}" href="{{ route('dns-zones.index') }}"><span class="nav-icon" aria-hidden="true">◎</span><span>DNS zones</span></a>
                    @endcan
                    @can('system.view')
                        <a class="side-link {{ request()->routeIs('system.*') ? 'active' : '' }}" href="{{ route('system.index') }}"><span class="nav-icon" aria-hidden="true">▤</span><span>System health</span></a>
                    @endcan
                    @can('audit.view')
                        <a class="side-link {{ request()->routeIs('audit.*') ? 'active' : '' }}" href="{{ route('audit.index') }}"><span class="nav-icon" aria-hidden="true">≡</span><span>Audit log</span></a>
                    @endcan
                    @can('api.view')
                        <a class="side-link {{ request()->routeIs('api-tokens.*') ? 'active' : '' }}" href="{{ route('api-tokens.index') }}"><span class="nav-icon" aria-hidden="true">⌘</span><span>API tokens</span></a>
                    @endcan
                    @can('license.view')
                        <a class="side-link {{ request()->routeIs('license.*') ? 'active' : '' }}" href="{{ route('license.index') }}"><span class="nav-icon" aria-hidden="true">◇</span><span>License</span></a>
                    @endcan
                </div>
            @elseif ($mode === 'reseller')
                <div class="nav-group">
                    <span class="side-section-label">Your customers</span>
                    @can('accounts.view')
                        <a class="side-link {{ request()->routeIs('accounts.index', 'accounts.show') ? 'active' : '' }}" href="{{ route('accounts.index') }}" @if (request()->routeIs('accounts.index', 'accounts.show')) aria-current="page" @endif>
                            <span class="nav-icon" aria-hidden="true">◉</span><span>Client accounts</span>
                        </a>
                    @endcan
                    @can('accounts.create')
                        <a class="side-link {{ request()->routeIs('accounts.create') ? 'active' : '' }}" href="{{ route('accounts.create') }}" @if (request()->routeIs('accounts.create')) aria-current="page" @endif>
                            <span class="nav-icon" aria-hidden="true">＋</span><span>New account</span>
                        </a>
                    @endcan
                    @can('packages.view')
                        <a class="side-link {{ request()->routeIs('packages.*') ? 'active' : '' }}" href="{{ route('packages.index') }}" @if (request()->routeIs('packages.*')) aria-current="page" @endif>
                            <span class="nav-icon" aria-hidden="true">▦</span><span>Plans & packages</span>
                        </a>
                    @endcan
                </div>
                <div class="nav-group">
                    <span class="side-section-label">Account</span>
                    <a class="side-link {{ request()->routeIs('security.*') ? 'active' : '' }}" href="{{ route('security.index') }}"><span class="nav-icon" aria-hidden="true">◇</span><span>Security & profile</span></a>
                </div>
            @else
                <div class="nav-group">
                    <span class="side-section-label">Websites</span>
                    @can('domains.view')
                        <a class="side-link {{ request()->routeIs('domains.*') ? 'active' : '' }}" href="{{ route('domains.index') }}" @if (request()->routeIs('domains.*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">◎</span><span>Domains</span></a>
                    @endcan
                    @can('files.view')
                        <a class="side-link {{ request()->routeIs('files.*', 'disk.*', 'ftp.*') ? 'active' : '' }}" href="{{ route('files.index') }}"><span class="nav-icon" aria-hidden="true">▤</span><span>File manager</span></a>
                    @endcan
                    @can('email.view')
                        <a class="side-link {{ request()->routeIs('email.*', 'forwarders.*') ? 'active' : '' }}" href="{{ route('email.index') }}"><span class="nav-icon" aria-hidden="true">✉</span><span>Email</span></a>
                    @endcan
                    @can('databases.view')
                        <a class="side-link {{ request()->routeIs('mysql.*', 'mysql-users.*', 'mysql-wizard.*') ? 'active' : '' }}" href="{{ route('mysql.index') }}"><span class="nav-icon" aria-hidden="true">▦</span><span>Databases</span></a>
                    @endcan
                </div>
                <div class="nav-group">
                    <span class="side-section-label">Tools</span>
                    @can('ssl.view')
                        <a class="side-link {{ request()->routeIs('ssl.*') ? 'active' : '' }}" href="{{ route('ssl.index') }}"><span class="nav-icon" aria-hidden="true">◇</span><span>SSL / security</span></a>
                    @endcan
                    @can('software.view')
                        <a class="side-link {{ request()->routeIs('php.*', 'apps.*') ? 'active' : '' }}" href="{{ route('php.index') }}"><span class="nav-icon" aria-hidden="true">⌘</span><span>Software</span></a>
                    @endcan
                    @can('metrics.view')
                        <a class="side-link {{ request()->routeIs('metrics.*', 'monitoring.*') ? 'active' : '' }}" href="{{ route('metrics.index') }}"><span class="nav-icon" aria-hidden="true">▥</span><span>Metrics</span></a>
                    @endcan
                    @can('cron.view')
                        <a class="side-link {{ request()->routeIs('cron.*') ? 'active' : '' }}" href="{{ route('cron.index') }}"><span class="nav-icon" aria-hidden="true">◷</span><span>Cron jobs</span></a>
                    @endcan
                    <a class="side-link {{ request()->routeIs('security.*') ? 'active' : '' }}" href="{{ route('security.index') }}"><span class="nav-icon" aria-hidden="true">⚙</span><span>Preferences</span></a>
                </div>
            @endif
        </nav>
        <div class="sidebar-footer">
            <span class="sidebar-status" aria-hidden="true"></span>
            <span>{{ $mode === 'cpanel' ? 'Account services' : 'Panel services' }} · {{ config('acp.version') }}</span>
        </div>
    </aside>

    <div class="panel-main">
        <main class="wrap" id="main-content" tabindex="-1">
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
        <footer class="wrap panel-footer muted">
            AlphaCP {{ config('acp.version') }} · {{ $workspaceName }}
        </footer>
    </div>
</div>

</body>
</html>
