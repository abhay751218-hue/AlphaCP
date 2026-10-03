@extends('layouts.guest')

@section('title', 'Login')

@section('content')
    <h1>Panel Login</h1>
    <p class="sub">Admin · Reseller · Customer — sab ek hi URL se</p>

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
