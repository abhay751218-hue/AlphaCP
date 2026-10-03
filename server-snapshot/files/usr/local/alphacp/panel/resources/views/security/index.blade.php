@extends('layouts.panel')

@section('title', 'Security')
@section('subtitle', 'Two-factor authentication, password aur active sessions')

@section('actions')
    <a class="btn small secondary" href="{{ route('security.sessions') }}">Active sessions</a>
    <a class="btn small secondary" href="{{ route('security.password') }}">Change password</a>
@endsection

@section('content')

<div class="grid cols-2">
    <div class="card">
        <h3>🔐 Two-Factor Authentication (2FA)
            <span class="badge {{ $user->two_factor_enabled ? 'green' : 'amber' }}">{{ $user->two_factor_enabled ? 'ON' : 'OFF' }}</span>
        </h3>

        @if ($user->two_factor_enabled)
            <p class="help">2FA on hai — har login par authenticator app ka 6-digit code lagega.</p>
            <form method="post" action="{{ route('security.2fa.disable') }}" class="mt">
                @csrf
                <label for="password">Confirm karne ke liye apna password daalo</label>
                <input id="password" name="password" type="password" required>
                <button class="btn danger mt" type="submit">2FA band karo</button>
            </form>

        @elseif ($pending)
            <p class="help">Authenticator app (Google Authenticator / Authy / 1Password) me ye secret add karo:</p>
            <div class="card" style="background:#0d1628; margin-top:10px">
                <div class="mono" style="font-size:16px; letter-spacing:1px">{{ $pretty }}</div>
                <p class="help">Ya ye link kholo: <span class="mono" style="word-break:break-all">{{ $otpauth }}</span></p>
            </div>

            <form method="post" action="{{ route('security.2fa.confirm') }}" class="mt">
                @csrf
                <label for="code">App me dikh raha 6-digit code</label>
                <input id="code" name="code" type="text" inputmode="numeric" maxlength="10" placeholder="123 456" required>
                <button class="btn mt" type="submit">Confirm & enable</button>
            </form>

        @else
            <p class="help">2FA abhi off hai. Panel ke login ko 2-step banao — recommended.</p>
            <form method="post" action="{{ route('security.2fa.start') }}" class="mt">
                @csrf
                <button class="btn" type="submit">2FA enable karo</button>
            </form>
        @endif
    </div>

    <div class="card">
        <h3>👤 Account</h3>
        <dl class="kv">
            <dt>Username</dt><dd class="mono">{{ $user->username }}</dd>
            <dt>Role</dt><dd>{{ $user->role?->label }} <span class="muted">({{ $user->role?->name }})</span></dd>
            <dt>Email</dt><dd>{{ $user->email ?? '—' }}</dd>
            <dt>Last login</dt><dd>{{ $user->last_login_at?->toDateTimeString() ?? '—' }}</dd>
            <dt>Last IP</dt><dd class="mono">{{ $user->last_login_ip ?? '—' }}</dd>
            <dt>Status</dt><dd><span class="badge {{ $user->status === 'active' ? 'green' : 'amber' }}">{{ $user->status }}</span></dd>
        </dl>

        <h3 class="mt">🧾 Aapke permissions ({{ count($user->permissionKeys()) }})</h3>
        <p class="help mono" style="line-height:1.9">
            @foreach (array_slice($user->permissionKeys(), 0, 30) as $key)
                <span class="badge blue" style="margin:2px 2px 0 0">{{ $key }}</span>
            @endforeach
            @if (count($user->permissionKeys()) > 30)
                <span class="muted"> +{{ count($user->permissionKeys()) - 30 }} more</span>
            @endif
        </p>
    </div>
</div>

<div class="card mt">
    <h3>📱 Active sessions ({{ $sessions->count() }})</h3>
    <div class="table-wrap">
        <table>
            <tr><th>Device</th><th>IP</th><th>Last seen</th><th></th></tr>
            @foreach ($sessions as $session)
                <tr>
                    <td>{{ $session->browser }}</td>
                    <td class="mono">{{ $session->ip_address }}</td>
                    <td class="muted">{{ $session->last_seen }}</td>
                    <td class="right">
                        @if (! hash_equals(session()->getId(), $session->id))
                            <form method="post" action="{{ route('security.sessions.destroy', $session->id) }}">
                                @csrf @method('DELETE')
                                <button class="btn small ghost" type="submit">Log out</button>
                            </form>
                        @else
                            <span class="badge green">ye session</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

@endsection
