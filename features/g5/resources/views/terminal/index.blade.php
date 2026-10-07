@extends('layouts.panel')

@section('title', 'Terminal')
@section('subtitle', 'AlphaCP Terminal (read-only whitelist — non-interactive)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($error)
<div class="card" style="border:2px solid #a22"><p>{{ $error }}</p></div>
@endif

<div class="card">
    <h3>Command chalao</h3>
    <form method="POST" action="{{ route('terminal.run') }}">
        @csrf
        <label>Command
            <input type="text" name="command" placeholder="ls -la" value="{{ $cmd }}" required>
        </label>
        <button class="btn" type="submit">Run</button>
    </form>
    <p class="muted">Allowed: ls, pwd, whoami, date, uname, df, free, uptime, git status, php -v, node -v, cat …</p>
</div>

@if ($cmd)
<div class="card">
    <h3>$ {{ $cmd }}</h3>
    <pre style="white-space:pre-wrap;background:#111;padding:12px;border-radius:6px">{{ $output }}</pre>
</div>
@endif
@endsection
