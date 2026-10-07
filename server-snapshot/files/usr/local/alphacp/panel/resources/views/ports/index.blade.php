@extends('layouts.panel')

@section('title', 'Ports Config')
@section('subtitle', 'Owner control — panel ke ports choose karo (8090 hamesha primary)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if (session('success'))
<div class="card" style="border:2px solid #2a2"><p>{{ session('success') }}</p></div>
@endif

<div class="card">
    <h3>Active ports</h3>
    <p>
        <strong>HTTPS:</strong> {{ implode(', ', $cfg['ssl']) }}<br>
        <strong>HTTP (redirect):</strong> {{ $cfg['http'] ? implode(', ', $cfg['http']) : '—' }}
    </p>
</div>

<div class="card">
    <h3>Ports set karo</h3>
    <form method="POST" action="{{ route('ports.store') }}">
        @csrf
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="cpanel" value="1" @checked($cfg['cpanel'])>
            Compatibility ports on karo (2083/2087/2096 + redirect 2082/2086/2095)
        </label>
        <label>Custom HTTPS ports (space/comma separated, 1024-65535)
            <input type="text" name="custom" value="{{ implode(' ', $cfg['custom']) }}" placeholder="8443 9091">
        </label>
        <button class="btn" type="submit">Save ports</button>
    </form>
    <p class="muted">8090 (primary) hamesha on rehta hai. Save ke baad apply-step chalayen taaki nginx par lage.</p>
</div>
@endsection
