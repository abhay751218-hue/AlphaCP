@php
    /**
     * AlphaCP panel shell — cPanel-parity workstream (docs/10-ui-parity-design.md).
     *
     * Ek hi shell, teen looks (body class + CSS tokens):
     *   theme-jupiter (cPanel customer)  → navy sidebar + orange accent + Tools grid + General Information column
     *   theme-whm     (root/reseller)    → WHM nav-tree (searchable) + Favorites home
     *   theme-webmail (mail-only)        → white/orange Webmail bar
     *
     * Sab variables layouts.panel ke view composer se aate hain (AppServiceProvider).
     */
    $theme        = $theme        ?? 'jupiter';
    $panelMode    = $panelMode    ?? 'cpanel';
    $themeLabel   = $themeLabel   ?? 'cPanel';
    $themePanel   = $themePanel   ?? 'AlphaCP';
    $themeTokens  = $themeTokens  ?? [];
    $navSections  = $navSections  ?? [];
    $searchIndex  = $searchIndex  ?? [];
    $generalInfo  = $generalInfo  ?? [];
    $statistics   = $statistics   ?? [];
    $styleOptions = $styleOptions ?? [];
    $isWhm        = $panelMode === 'whm';
    $isWebmail    = $theme === 'webmail';
    $hasSidebar   = ! $isWebmail;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · {{ $themePanel }}</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ config('acp.version') }}">
    <style>
        :root {
            --cp-nav: {{ $themeTokens['nav'] ?? '#293a4a' }};
            --cp-nav-dark: {{ $themeTokens['nav-dark'] ?? '#22303f' }};
            --cp-accent: {{ $themeTokens['accent'] ?? '#ff6c2c' }};
            --cp-accent-2: {{ $themeTokens['accent-2'] ?? '#179bd7' }};
            --cp-bg: {{ $themeTokens['bg'] ?? '#eef1f4' }};
        }
    </style>
</head>
<body class="acp theme-{{ $theme }} mode-{{ $panelMode }}" data-theme="{{ $theme }}">
<a class="skip-link" href="#acp-content">Skip to main content</a>

<header class="topbar">
    @if ($hasSidebar)
        <button type="button" class="nav-toggle" id="acpNavToggle" aria-controls="acpSidebar" aria-expanded="true"
                aria-label="Toggle navigation">&#9776;</button>
    @endif

    <a class="brand" href="{{ route('dashboard') }}">
        <span class="logo" aria-hidden="true">A</span>
        <span class="brand-text">
            {{ $themePanel }}
            <small>{{ $isWebmail ? 'Webmail' : ($isWhm ? 'Web Host Manager' : 'Control Panel') }} · {{ config('acp.version') }}</small>
        </span>
    </a>

    <form class="cp-search" role="search" onsubmit="return false" autocomplete="off">
        <label class="sr-only" for="acpSearch">Search tools</label>
        <input id="acpSearch" type="search" placeholder="Find functions quickly…" data-nav-search>
        <div class="cp-search-results" id="acpSearchResults" hidden></div>
    </form>

    <span class="spacer"></span>

    @if (! $isWebmail)
        <div class="meta server-meta">
            <span class="muted">server</span> <strong>{{ $server['hostname'] ?? 'unknown' }}</strong><br>
            <span class="muted">panel</span> {{ config('acp.version') }} · <span class="muted">agent</span> {{ config('acp.agent_version') }}
        </div>
    @endif

    <div class="user">
        <span class="avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()?->username ?? 'A', 0, 1)) }}</span>
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

<div class="shell">
    @if ($hasSidebar)
        <aside class="sidebar" id="acpSidebar" aria-label="Panel navigation">
            <nav class="cp-nav">
                <div class="cp-nav-home">
                    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                        <span class="cp-nav-ico">&#127968;</span> Home
                    </a>
                </div>

                @foreach ($navSections as $key => $section)
                    <details class="cp-nav-group" open>
                        <summary>
                            <span class="cp-nav-ico">{{ \App\Support\NavIcon::glyph($section['icon'] ?? 'cog') }}</span>
                            <span class="cp-nav-label">{{ $section['label'] }}</span>
                        </summary>
                        <ul>
                            @foreach ($section['items'] as $item)
                                @php $live = ($item['status'] ?? 'step') === 'live' && ! empty($item['route']); @endphp
                                <li>
                                    @if ($live)
                                        <a href="{{ route($item['route']) }}">{{ $item['name'] }}</a>
                                    @else
                                        <span class="soon" title="Step {{ $item['step'] }} me aayega">{{ $item['name'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </nav>

            @if (! empty($styleOptions) && count($styleOptions) > 1)
                <div class="cp-nav-foot">
                    <form method="post" action="{{ route('preferences.style') }}">
                        @csrf
                        <label class="muted" for="acpStyle">Style</label>
                        <select id="acpStyle" name="theme" onchange="this.form.submit()">
                            @foreach ($styleOptions as $value => $label)
                                <option value="{{ $value }}" @selected($value === $theme)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            @endif
        </aside>
    @endif

    <main class="wrap" id="acp-content">
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

        @hasSection('fullpage')
            @yield('fullpage')
        @else
            <div class="content-2col">
                <div class="col-main">
                    @yield('content')
                </div>

                @if (! $isWhm && ! $isWebmail)
                    <aside class="col-side" aria-label="Account information">
                        @include('partials.general-info', ['rows' => $generalInfo])
                        @include('partials.statistics', ['stats' => $statistics])
                    </aside>
                @elseif ($isWhm)
                    <aside class="col-side" aria-label="Server information">
                        @include('partials.general-info', ['rows' => $generalInfo, 'title' => 'Server Information'])
                    </aside>
                @endif
            </div>
        @endif
    </main>
</div>

<footer class="wrap panel-foot">
    {{ $themePanel }} {{ config('acp.version') }} — AlphaCP control panel · {{ $themeLabel }} style ·
    parity contract: <span class="mono">docs/09-cpanel-parity-checklist.md</span>
</footer>

<script src="{{ asset('assets/panel.js') }}?v={{ config('acp.version') }}" defer></script>
<script type="application/json" id="acpNavIndex">{!! json_encode($searchIndex, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
</body>
</html>
