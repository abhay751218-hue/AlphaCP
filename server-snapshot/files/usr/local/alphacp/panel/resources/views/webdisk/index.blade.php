@extends('layouts.panel')

@section('title', 'Web Disk')
@section('subtitle', 'WebDAV accounts — files ko desktop/client se access karo (read-only / read-write)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($error !== null)
    <div class="flash error">{{ $error }}</div>
@endif

<div class="card">
    <h3>Naya Web Disk account / password reset</h3>
    <form method="POST" action="{{ route('webdisk.store') }}">
        @csrf
        <label>Login
            <input type="text" name="login" placeholder="designer" pattern="[A-Za-z0-9._-]+" maxlength="60" required>
        </label>
        <label>Password
            <input type="password" name="password" minlength="8" maxlength="128" required>
        </label>
        <label>Permissions
            <select name="permissions" required>
                <option value="rw">Read-Write</option>
                <option value="ro">Read-Only</option>
            </select>
        </label>
        <button class="btn" type="submit">Save account</button>
    </form>
    <p class="muted">Maujooda login dobara save karna = password/permissions reset.</p>
</div>

<div class="card">
    <h3>Web Disk accounts ({{ count($accounts) }})</h3>
    @if ($accounts === [])
        <p class="muted">Koi Web Disk account nahi.</p>
    @else
        <table>
            <tr><th>Login</th><th>Permissions</th><th></th></tr>
            @foreach ($accounts as $a)
            <tr>
                <td>{{ $a['login'] ?? '' }}</td>
                <td>{{ ($a['permissions'] ?? '') === 'rw' ? 'Read-Write' : 'Read-Only' }}</td>
                <td>
                    <form method="POST" action="{{ route('webdisk.destroy', $a['login'] ?? '') }}" onsubmit="return confirm('Delete?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>

<div class="card">
    <h3>Connect (WebDAV client)</h3>
    <p>URL: <code>https://{{ request()->getHost() }}/webdisk/</code> · Realm: <code>{{ $realm }}</code></p>
    <p class="muted">Digest authentication — wahi login/password jo upar set kiya. Read-Only accounts
       sirf padh sakte hain (write methods agent-side deny hain).</p>
</div>
@endsection
