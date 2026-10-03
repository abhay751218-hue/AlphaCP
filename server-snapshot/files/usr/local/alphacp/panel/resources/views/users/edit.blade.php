@extends('layouts.panel')

@section('title', 'Edit: ' . $user->username)
@section('subtitle', 'Role, status aur password reset')

@section('actions')
    <a class="btn small secondary" href="{{ route('users.index') }}">← Users</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>✏️ Details</h3>
        <form method="post" action="{{ route('users.update', $user) }}">
            @csrf @method('PUT')

            <label>Username</label>
            <input value="{{ $user->username }}" disabled>

            <label for="full_name">Poora naam</label>
            <input id="full_name" name="full_name" value="{{ old('full_name', $user->full_name) }}">

            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}">

            <label for="role_id">Role</label>
            <select id="role_id" name="role_id" required>
                @foreach ($roles as $role)
                    @continue($role->isRoot() && ! auth()->user()->isRoot())
                    <option value="{{ $role->id }}" @selected($user->role_id === $role->id)>
                        {{ $role->label }} ({{ $role->name }})
                    </option>
                @endforeach
            </select>

            <label for="status">Status</label>
            <select id="status" name="status" required>
                @foreach (['active', 'suspended', 'locked'] as $status)
                    <option value="{{ $status }}" @selected($user->status === $status)>{{ $status }}</option>
                @endforeach
            </select>

            <button class="btn mt" type="submit">Save</button>
        </form>
    </div>

    <div class="card">
        <h3>🔑 Password reset</h3>
        <p class="help">Naya temporary password set karo. User ko next login par badalna padega; 2FA bhi reset ho jayega.</p>
        <form method="post" action="{{ route('users.password', $user) }}">
            @csrf
            <label for="password">Naya temporary password</label>
            <input id="password" name="password" type="password" required>
            <button class="btn danger mt" type="submit">Reset password</button>
        </form>

        <h3 class="mt">📋 Info</h3>
        <dl class="kv">
            <dt>Last login</dt><dd>{{ $user->last_login_at?->toDateTimeString() ?? 'kabhi nahi' }}</dd>
            <dt>Last IP</dt><dd class="mono">{{ $user->last_login_ip ?? '—' }}</dd>
            <dt>2FA</dt><dd>{{ $user->two_factor_enabled ? 'ON' : 'OFF' }}</dd>
            <dt>Created</dt><dd>{{ $user->created_at?->toDateTimeString() }}</dd>
        </dl>
    </div>
</div>
@endsection
