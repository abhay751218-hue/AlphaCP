@extends('layouts.guest')

@section('title', 'Two-Factor Authentication')

@section('content')
    <h1>2-Step Verification</h1>
    <p class="sub">Authenticator app me dikh raha 6-digit code daalo</p>

    <form method="post" action="{{ route('twofactor.verify') }}">
        @csrf

        <label for="code">Verification code</label>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="10"
               autocomplete="one-time-code" placeholder="123 456" required autofocus>

        <button class="btn mt" type="submit" style="width:100%; justify-content:center">Verify</button>
    </form>

    <form method="post" action="{{ route('logout') }}" class="mt">
        @csrf
        <button class="btn ghost small" type="submit">Cancel & logout</button>
    </form>

    <p class="help mt">Code 30 second me badalta hai. Purana code dobara kaam nahi karega (replay protection).</p>
@endsection
