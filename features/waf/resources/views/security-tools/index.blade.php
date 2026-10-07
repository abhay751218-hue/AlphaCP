@extends('layouts.panel')

@section('title', 'Security Tools')
@section('subtitle', 'ModSecurity (WAF) + Virus Scanner (ClamAV) — cPanel Security jaisa')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>ModSecurity (WAF)</h3>
    <p>Status: <strong>{{ $modsec ? 'ENABLED' : 'DISABLED' }}</strong></p>
    <form method="POST" action="{{ route('security-tools.modsec') }}">
        @csrf
        <button class="btn" type="submit">{{ $modsec ? 'Disable WAF' : 'Enable WAF' }}</button>
    </form>
</div>

<div class="card">
    <h3>Virus Scanner (ClamAV)</h3>
    <p class="muted">Account ke <code>public_html</code> par scan chalata hai.</p>
    <form method="POST" action="{{ route('security-tools.scan') }}">
        @csrf
        <button class="btn" type="submit">Scan now</button>
    </form>
    @if (session('scan_output'))
        <pre class="muted">{{ session('scan_output') }}</pre>
    @endif
</div>
@endsection
