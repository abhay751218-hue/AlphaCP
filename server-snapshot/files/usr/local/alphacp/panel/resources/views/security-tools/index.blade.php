@extends('layouts.panel')

@section('title', 'Security Tools')
@section('subtitle', 'ModSecurity (WAF) + Virus Scanner (ClamAV)')

@section('actions')
    <a class="btn small secondary" href="{{ route('ip-blocker.index') }}">IP Blocker</a>
    <a class="btn small secondary" href="{{ route('audit.index') }}">Audit Log</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) ModSecurity (WAF)
            <span class="badge {{ $modsec ? 'green' : 'amber' }}">{{ $modsec ? 'ENABLED' : 'DISABLED' }}</span>
        </h3>
        <p class="help">Web Application Firewall — SQLi/XSS/bot attacks ko request level par block karta hai.</p>
        <form method="POST" action="{{ route('security-tools.modsec') }}">
            @csrf
            <button class="btn {{ $modsec ? 'danger' : '' }} mt" type="submit">{{ $modsec ? 'Disable WAF' : 'Enable WAF' }}</button>
        </form>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico']) Virus Scanner (ClamAV)</h3>
        <p class="help">Account ke <code>public_html</code> par on-demand malware scan chalata hai.</p>
        <form method="POST" action="{{ route('security-tools.scan') }}">
            @csrf
            <button class="btn mt" type="submit">Scan Now</button>
        </form>
    </div>
</div>

@if (session('scan_output'))
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) Scan Result</h3>
    <pre class="muted">{{ session('scan_output') }}</pre>
</div>
@endif
@endsection
