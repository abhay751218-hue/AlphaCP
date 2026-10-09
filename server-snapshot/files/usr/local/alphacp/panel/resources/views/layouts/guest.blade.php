<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Login') · AlphaCP</title>
    <link rel="stylesheet" href="{{ asset('assets/panel.css') }}?v={{ config('acp.version') }}">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="wm-mark">@yield('wordmark', 'AlphaCP')</div>

        <div class="card">
            @include('partials.flash', [])
            @yield('content')
        </div>

        <div class="auth-foot">
            AlphaCP {{ config('acp.version') }} · {{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'server' }}
        </div>
    </div>
</div>
</body>
</html>
