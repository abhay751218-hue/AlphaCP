@extends('layouts.panel')

@section('title', 'License & Trial')
@section('subtitle', 'Panel license status — customer websites and email are never blocked')

@section('content')
@php
    $state = $status['state'] ?? 'uninitialized';
    $badge = match ($state) {
        'active', 'trial' => 'green',
        'notice', 'grace' => 'amber',
        'invalid', 'locked' => 'red',
        default => 'blue',
    };
@endphp

<div class="card">
    <div class="row" style="align-items:center; justify-content:space-between; gap:16px">
        <div>
            <h3>Current status</h3>
            <p class="help">{{ $status['message'] ?? 'Status unavailable.' }}</p>
        </div>
        <span class="badge {{ $badge }}" style="font-size:14px">{{ $status['label'] ?? 'UNKNOWN' }}</span>
    </div>

    <div class="grid cols-2 mt">
        <div>
            <div class="kv"><span>Tier</span><span>{{ $status['tier'] ?: '—' }}</span></div>
            <div class="kv"><span>License ID</span><span class="mono">{{ $status['license_uid'] ?: '—' }}</span></div>
            <div class="kv"><span>Source</span><span>{{ $status['source'] ?: '—' }}</span></div>
        </div>
        <div>
            <div class="kv"><span>Expires</span><span>{{ $status['expires_at'] ?: '—' }}</span></div>
            <div class="kv"><span>Days left</span><span>{{ $status['days_left'] ?? '—' }}</span></div>
            <div class="kv"><span>Fingerprint</span><span class="mono">{{ $status['fingerprint'] ?: '—' }}</span></div>
        </div>
    </div>
</div>

<div class="card mt">
    <h3>Activate a paid license</h3>
    <p class="help">License server configure na ho to local trial active rahega. Key ko chat, logs ya screenshots me share na karein.</p>
    <form method="post" action="{{ route('license.activate') }}" class="row mt" style="align-items:end; gap:12px">
        @csrf
        <label style="flex:1">License key
            <input type="text" name="license_key" value="{{ old('license_key') }}" placeholder="ACPP-XXXX-XXXX-XXXX" maxlength="160" required>
        </label>
        <button class="btn" type="submit">Activate</button>
    </form>
    @error('license_key')
        <p class="error mt">{{ $message }}</p>
    @enderror
</div>

<div class="card mt">
    <h3>Golden rule</h3>
    <p class="help">License expiry or a license-server outage will not stop customer websites, email, DNS, or backups. Only privileged panel actions degrade.</p>
</div>
@endsection
