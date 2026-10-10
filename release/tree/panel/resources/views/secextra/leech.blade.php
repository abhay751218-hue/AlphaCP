@extends('layouts.panel')

@section('title', 'Leech Protection')
@section('subtitle', 'Password-sharing / brute-force se accounts bachao (login limit)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Leech Protection</h3>
    <form method="POST" action="{{ route('secextra.store') }}">
        @csrf
        <input type="hidden" name="kind" value="leech">
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="enabled" value="1" @checked($s['enabled'])> Enabled
        </label>
        <label>Max logins (24h me, per user)
            <input type="number" name="max_logins" value="{{ $s['max_logins'] }}" min="1" max="20">
        </label>
        <label>Limit cross karne par
            <select name="action">
                <option value="block" @selected($s['action'] === 'block')>Block login</option>
                <option value="redirect" @selected($s['action'] === 'redirect')>Redirect to URL</option>
            </select>
        </label>
        <button class="btn" type="submit">Save</button>
    </form>
</div>
@endsection
