{{-- REBRAND_DONE --}}
@php
    $isWhm    = ($panelMode ?? 'cpanel') === 'whm';
    $modeKey  = $isWhm ? 'whm' : 'cpanel';
    $active   = static fn (string ...$patterns): string => request()->routeIs(...$patterns) ? 'active' : '';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · AlphaCP</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ config('acp.version') }}">
</head>
<body data-panel="@yield('panel-theme', $modeKey)">

<header class="topbar">
    <div class="brand">
        <span class="logo">A</span>
        <span>
            AlphaCP {{ $isWhm ? 'Server Manager' : '' }}
            <small>{{ $isWhm ? 'Server Manager · admin' : 'Account Panel' }} · {{ config('acp.version') }}</small>
        </span>
    </div>

    @unless ($isWhm)
    <nav class="topnav" aria-label="Main">
        <a href="{{ route('dashboard') }}">Dashboard</a>
        @can('domains.view')<a href="{{ route('domains.index') }}">Domains</a>@endcan
        @can('email.view')<a href="{{ route('email.index') }}">Email</a>@endcan
        @can('files.view')<a href="{{ route('files.index') }}">Files</a>@endcan
        @can('databases.view')<a href="{{ route('mysql.index') }}">Databases</a>@endcan
        @can('software.view')<a href="{{ route('php.index') }}">MultiPHP</a>@endcan
        @can('cron.view')<a href="{{ route('cron.index') }}">Cron</a>@endcan
        @can('ssl.view')<a href="{{ route('ssl.index') }}">SSL</a>@endcan
        <a href="{{ route('security.index') }}">Security</a>
    </nav>
    @endunless

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

@if ($isWhm)
<div class="whm-layout">
    <aside class="side" aria-label="Server Manager">
        <div class="side-server">
            <b>{{ $server['hostname'] ?? 'server' }}</b>
            panel {{ config('acp.version') }} · agent {{ config('acp.agent_version') }}
        </div>

        <div class="side-group">
            <h4>Server</h4>
            <a class="side-link {{ $active('system.index') }}" href="{{ route('system.index') }}"><span class="ico">🖥️</span>System Information</a>
            @can('system.services')<a class="side-link {{ $active('system.services') }}" href="{{ route('system.services') }}"><span class="ico">⚙️</span>Service Status</a>@endcan
            @can('system.tasks')<a class="side-link {{ $active('system.tasks') }}" href="{{ route('system.tasks') }}"><span class="ico">📋</span>Task Queue</a>@endcan
            @can('audit.view')<a class="side-link {{ $active('audit.index') }}" href="{{ route('audit.index') }}"><span class="ico">📜</span>Audit Log</a>@endcan
        </div>

        @can('accounts.view')
        <div class="side-group">
            <h4>Accounts</h4>
            @can('accounts.create')<a class="side-link {{ $active('accounts.create') }}" href="{{ route('accounts.create') }}"><span class="ico">➕</span>Create Account</a>@endcan
            <a class="side-link {{ $active('accounts.index', 'accounts.show') }}" href="{{ route('accounts.index') }}"><span class="ico">👥</span>List Accounts</a>
            @can('packages.view')<a class="side-link {{ $active('packages.*') }}" href="{{ route('packages.index') }}"><span class="ico">📦</span>Packages</a>@endcan
            @can('users.view')<a class="side-link {{ $active('users.*') }}" href="{{ route('users.index') }}"><span class="ico">🧑‍💼</span>User Manager</a>@endcan
            @can('users.manage')<a class="side-link {{ $active('resellers.*') }}" href="{{ route('resellers.index') }}"><span class="ico">🏷️</span>Resellers</a>@endcan
        </div>
        @endcan

        @can('dns.view')
        <div class="side-group">
            <h4>DNS</h4>
            <a class="side-link {{ $active('dns-zones.*', 'zone-editor.*') }}" href="{{ route('dns-zones.index') }}"><span class="ico">🌐</span>Zone Manager</a>
            <a class="side-link {{ $active('zone-templates.*') }}" href="{{ route('zone-templates.index') }}"><span class="ico">📄</span>Zone Templates</a>
            <a class="side-link {{ $active('hostname-a.*') }}" href="{{ route('hostname-a.index') }}"><span class="ico">🔤</span>Hostname A Entry</a>
            <a class="side-link {{ $active('nameserver-selection.*') }}" href="{{ route('nameserver-selection.index') }}"><span class="ico">🧭</span>Nameservers</a>
            <a class="side-link {{ $active('dns-cluster.*') }}" href="{{ route('dns-cluster.index') }}"><span class="ico">🔗</span>DNS Cluster</a>
            <a class="side-link {{ $active('park-domain.*') }}" href="{{ route('park-domain.index') }}"><span class="ico">🅿️</span>Park a Domain</a>
            <a class="side-link {{ $active('dns-cleanup.*') }}" href="{{ route('dns-cleanup.index') }}"><span class="ico">🧹</span>DNS Cleanup</a>
            <a class="side-link {{ $active('zone-ttl.*') }}" href="{{ route('zone-ttl.index') }}"><span class="ico">⏱️</span>Zone TTL</a>
            <a class="side-link {{ $active('domain-forward.*') }}" href="{{ route('domain-forward.index') }}"><span class="ico">↪️</span>Domain Forwarding</a>
            <a class="side-link {{ $active('dns-sync.*') }}" href="{{ route('dns-sync.index') }}"><span class="ico">🔄</span>Synchronize DNS</a>
            <a class="side-link {{ $active('ns-report.*') }}" href="{{ route('ns-report.index') }}"><span class="ico">📊</span>NS Report</a>
            <a class="side-link {{ $active('global-email-routing.*') }}" href="{{ route('global-email-routing.index') }}"><span class="ico">📮</span>Email Routing</a>
        </div>
        @endcan

        @can('backup.view')
        <div class="side-group">
            <h4>Backup &amp; Transfer</h4>
            <a class="side-link {{ $active('backup-config.*') }}" href="{{ route('backup-config.index') }}"><span class="ico">🗓️</span>Backup Config</a>
            <a class="side-link {{ $active('backup-destinations.*') }}" href="{{ route('backup-destinations.index') }}"><span class="ico">☁️</span>Destinations</a>
            <a class="side-link {{ $active('backup-restoration.*') }}" href="{{ route('backup-restoration.index') }}"><span class="ico">♻️</span>Restoration</a>
            <a class="side-link {{ $active('backup-user-selection.*') }}" href="{{ route('backup-user-selection.index') }}"><span class="ico">✅</span>User Selection</a>
            <a class="side-link {{ $active('file-directory-restoration.*') }}" href="{{ route('file-directory-restoration.index') }}"><span class="ico">📁</span>File Restoration</a>
            <a class="side-link {{ $active('transfer-tool.*') }}" href="{{ route('transfer-tool.index') }}"><span class="ico">🚚</span>Transfer Tool</a>
            <a class="side-link {{ $active('transfer-restore.*') }}" href="{{ route('transfer-restore.index') }}"><span class="ico">📥</span>Transfer / Restore</a>
            <a class="side-link {{ $active('transfer-review.*') }}" href="{{ route('transfer-review.index') }}"><span class="ico">🔍</span>Review Transfers</a>
        </div>
        @endcan

        @can('security.view')
        <div class="side-group">
            <h4>Security</h4>
            <a class="side-link {{ $active('security.index') }}" href="{{ route('security.index') }}"><span class="ico">🛡️</span>Security Center</a>
            <a class="side-link {{ $active('security-tools.*') }}" href="{{ route('security-tools.index') }}"><span class="ico">🧰</span>Security Tools</a>
            <a class="side-link {{ $active('ip-blocker.*') }}" href="{{ route('ip-blocker.index') }}"><span class="ico">⛔</span>IP Blocker</a>
            <a class="side-link {{ $active('ssh.*') }}" href="{{ route('ssh.index') }}"><span class="ico">🔑</span>SSH Access</a>
        </div>
        @endcan

        <div class="side-group">
            <h4>System</h4>
            @can('api.view')<a class="side-link {{ $active('api-tokens.*') }}" href="{{ route('api-tokens.index') }}"><span class="ico">🎫</span>API Tokens</a>@endcan
            @can('license.view')<a class="side-link {{ $active('license.index') }}" href="{{ route('license.index') }}"><span class="ico">📜</span>License &amp; Trial</a>@endcan
            @can('license.manage')<a class="side-link {{ $active('license-server.*') }}" href="{{ route('license-server.index') }}"><span class="ico">🏢</span>License Server</a>@endcan
            <a class="side-link {{ $active('ports.*') }}" href="{{ route('ports.index') }}"><span class="ico">🔌</span>Ports</a>
            @can('metrics.view')<a class="side-link {{ $active('monitoring.*') }}" href="{{ route('monitoring.index') }}"><span class="ico">📈</span>Monitoring</a>@endcan
            <a class="side-link {{ $active('apps.*') }}" href="{{ route('apps.index') }}"><span class="ico">🧩</span>Apps</a>
            <a class="side-link {{ $active('terminal.*') }}" href="{{ route('terminal.index') }}"><span class="ico">⌨️</span>Terminal</a>
        </div>
    </aside>

    <main class="whm-main">
        <div class="wrap">
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
        </div>
    </main>
</div>
@else
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
@endif

<footer class="wrap muted" style="padding-top:0; font-size:12.5px">
    AlphaCP {{ config('acp.version') }} — AlphaCP control panel ·
    parity checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span>
</footer>

</body>
</html>
