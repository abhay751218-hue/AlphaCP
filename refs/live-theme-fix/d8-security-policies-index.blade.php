@extends('layouts.panel')

@section('title', 'Security Policies')
@section('subtitle', 'Panel-wide security posture — 2FA adoption, shield services, enforced policies')

@section('actions')
    <a class="btn small secondary" href="{{ route('audit.index') }}">Audit Log</a>
    <a class="btn small secondary" href="{{ route('security.index') }}">Password & 2FA</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico']) Posture summary</h3>
        <div class="row mt" style="gap:20px; flex-wrap:wrap">
            <div class="stat"><span class="num">{{ $twofaOn }}/{{ $users->count() }}</span><span class="unit">users with 2FA</span></div>
            <div class="stat"><span class="num">{{ $activeShields }}/{{ count($services) ?: 6 }}</span><span class="unit">shield services active</span></div>
            <div class="stat"><span class="num">{{ $agentOk ? 'OK' : '—' }}</span><span class="unit">root agent</span></div>
        </div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Ye page kya dikhata hai</h3>
        <p class="help" style="margin:6px 0 0">Sab data <strong>asli</strong> hai — users table se 2FA adoption,
            root agent se shield services ka systemd state, aur neeche wo policies jo panel ke code me
            enforce hain. Kisi user ka 2FA uski <strong>Password &amp; Security</strong> page se on hota hai.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'lock', 'cls' => 'hico']) Enforced policies</h3>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Policy</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
            @foreach ($policies as $p)
                <tr>
                    <td><strong>{{ $p['name'] }}</strong></td>
                    <td>
                        @if ($p['state'] === 'enforced')
                            <span class="badge green">enforced</span>
                        @else
                            <span class="badge blue">live check</span>
                        @endif
                    </td>
                    <td class="muted">{{ $p['desc'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="grid cols-2 mt">
    <div class="card">
        <div class="row mb">
            <h3 style="margin:0">@include('partials.icons', ['icon' => 'services', 'cls' => 'hico']) Shield services</h3>
        </div>
        @if (! $agentOk)
            <p class="empty">Root agent offline — service state nahi mil saka.</p>
        @elseif ($services === [])
            <p class="empty">Agent se service state nahi aaya — thodi der baad refresh karo.</p>
        @else
        <div class="table-wrap">
            <table>
                <thead><tr><th>Service</th><th>State</th><th>Boot</th></tr></thead>
                <tbody>
                @foreach ($services as $name => $state)
                    <tr>
                        <td class="mono">{{ $name }}</td>
                        <td><span class="badge {{ ($state['active'] ?? '') === 'active' ? 'green' : 'amber' }}">{{ $state['active'] ?? '?' }}</span></td>
                        <td class="muted">{{ $state['enabled'] ?? '?' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    <div class="card">
        <div class="row mb">
            <h3 style="margin:0">@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) 2FA adoption</h3>
            <span class="push"></span>
            <input type="search" class="searchbox" style="width:min(220px,100%)" placeholder="Filter users…" data-filter-rows="#acp-secpol-users tbody tr" aria-label="Filter users">
        </div>
        <div class="table-wrap">
            <table id="acp-secpol-users">
                <thead><tr><th>User</th><th>Role</th><th>2FA</th><th>Force PW change</th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td class="mono">{{ $u->username }}</td>
                        <td class="muted">{{ $u->role?->label ?? '—' }}</td>
                        <td>
                            @if ($u->two_factor_enabled)
                                <span class="badge green">enabled</span>
                            @else
                                <span class="badge amber">off</span>
                            @endif
                        </td>
                        <td>
                            @if ($u->force_password_change)
                                <span class="badge blue">pending</span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
