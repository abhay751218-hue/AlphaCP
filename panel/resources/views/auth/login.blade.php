<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Login · AlphaCP</title>
  <link rel="stylesheet" href="{{ asset('css/panel.css') }}">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="mark-lg">A</div>
    <h1>AlphaCP</h1>
    <div class="tag">Server: <b>{{ $serverName }}</b> · panel v{{ $panelVersion }}</div>

    @if (session('error'))
      <div class="flash err">{{ session('error') }}</div>
    @endif
    @if (session('status'))
      <div class="flash ok">{{ session('status') }}</div>
    @endif

    <form method="post" action="{{ route('login.attempt') }}" autocomplete="off">
      @csrf
      <label for="username">Username</label>
      <input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus autocomplete="username">
      @error('username')<div class="small" style="color:var(--red)">{{ $message }}</div>@enderror

      <label for="password">Password</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
      @error('password')<div class="small" style="color:var(--red)">{{ $message }}</div>@enderror

      <button class="btn primary" type="submit">Login</button>
    </form>

    <div class="login-foot">
      Secure area — all logins are audited.<br>
      {{-- 2FA prompt arrives with the 2FA module (Step 2B-2) --}}
    </div>
  </div>
</div>
</body>
</html>
