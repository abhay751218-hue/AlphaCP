@extends('layouts.guest')

@section('title', 'Login')

@section('wordmark', config('acp.brand.name', 'AlphaCP'))

@section('wordmark-sub', (($portFamily ?? null) === 'whm') ? 'WHM · Server Manager' : ((($portFamily ?? null) === 'cpanel') ? 'cPanel · Account Panel' : 'Control Panel'))

@section('content')
    <h1>{{ ($portFamily ?? null) === 'whm' ? 'WHM Login' : (($portFamily ?? null) === 'cpanel' ? 'cPanel Login' : 'Panel Login') }}</h1>
    <p class="sub">@if(($portFamily ?? null) === 'whm')
            Root · Reseller — server management
        @elseif(($portFamily ?? null) === 'cpanel')
            Customer — hosting control
        @else
            Admin · Reseller · Customer — sab ek hi URL se
        @endif</p>

    <form method="post" action="{{ route('login.attempt') }}">
        @csrf

        <label for="username">Username</label>
        <input id="username" name="username" type="text" value="{{ old('username') }}"
               autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button class="btn mt" type="submit" style="width:100%; justify-content:center">Login</button>
    </form>

    <p class="help mt">Too many failed passwords lock the account for a short time (brute-force protection).</p>
@endsection
