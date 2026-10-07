<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Login') · {{ config('acp.ui.brand_name', 'AlphaCP') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ config('acp.version') }}">
</head>
<body class="acp theme-guard">
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand">
            <span class="logo" aria-hidden="true">A</span>
            <span class="auth-brand-text">
                {{ config('acp.ui.brand_name', 'AlphaCP') }}
                <small>@yield('panel-name', 'Control Panel')</small>
            </span>
        </div>

        <div class="card auth-box">
            @include('partials.flash', [])
            @yield('content')
        </div>

        <div class="auth-foot">
            {{ config('acp.ui.brand_name', 'AlphaCP') }} {{ config('acp.version') }} ·
            {{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'server' }}
            <br>
            <span class="muted">cPanel-parity: docs/09-cpanel-parity-checklist.md · design: docs/10-ui-parity-design.md</span>
        </div>
    </div>
</div>
</body>
</html>
