@extends('layouts.panel')

@section('title', 'License Server')
@section('subtitle', 'Sellable signed licenses — customers ke liye keys issue/verify/revoke')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($newKey)
<div class="card" style="border:2px solid #2a2">
    <h3>Naya license key (EK baar — copy karo)</h3>
    <p><code style="word-break:break-all">{{ $newKey }}</code></p>
    <p class="muted">Ye key customer ko do — unka panel ise offline verify karega.</p>
</div>
@endif

<div class="card">
    <h3>License issue karo</h3>
    <form method="POST" action="{{ route('license-server.store') }}">
        @csrf
        <label>Server ID
            <input type="text" name="server_id" placeholder="srv-001 / IP / domain" required>
        </label>
        <label>Plan
            <select name="plan" required>
                <option value="starter">starter (10 accounts)</option>
                <option value="pro">pro (50 accounts)</option>
                <option value="business">business (200 accounts)</option>
                <option value="owner">owner (UNLIMITED + lifetime)</option>
            </select>
        </label>
        <label>Din (validity; 0 = lifetime, sirf owner)
            <input type="number" name="days" value="365" min="0" max="36500" required>
        </label>
        <button class="btn" type="submit">Issue license</button>
    </form>
</div>

@if ($publicPem)
<div class="card">
    <h3>Public key (customer panel ke .env me ACP_LICENSE_PUBLIC_KEY)</h3>
    <pre style="word-break:break-all;white-space:pre-wrap">{{ $publicPem }}</pre>
    <p class="muted">Customer apne panel me ye key daalega — offline Ed25519 verify ke liye.</p>
</div>
@else
<div class="card">
    <p class="muted">Sodium extension is host par nahi — licenses online-verified mode me chalenge (hmac).</p>
</div>
@endif

<div class="card">
    <h3>Issued licenses ({{ $keys->count() }})</h3>
    @if ($keys->isEmpty())
        <p class="muted">Abhi koi license nahi.</p>
    @else
        <table>
            <tr><th>Server</th><th>Plan</th><th>Expires</th><th>Status</th><th></th></tr>
            @foreach ($keys as $k)
            <tr>
                <td>{{ $k->server_id }}</td>
                <td>{{ $k->plan }}</td>
                <td>{{ $k->expires_at?->format('d M Y') }}</td>
                <td>{{ $k->revoked ? 'REVOKED' : 'active' }}</td>
                <td>
                    @unless ($k->revoked)
                    <form method="POST" action="{{ route('license-server.destroy', $k) }}" onsubmit="return confirm('Revoke karein?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Revoke</button>
                    </form>
                    @endunless
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
