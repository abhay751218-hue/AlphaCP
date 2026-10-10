@extends('layouts.panel')

@section('title', 'Change Password')
@section('subtitle', 'The new password must match the policy')

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>🔑 Password</h3>
        <form method="post" action="{{ route('security.password.update') }}">
            @csrf
            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password">

            <label for="password">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">

            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

            <button class="btn mt" type="submit">Password badlo</button>
        </form>
    </div>

    <div class="card">
        <h3>📏 Policy</h3>
        <ul class="help" style="margin-left:18px; line-height:1.9">
            <li>Minimum {{ config('acp.security.password_min_length') }} characters</li>
            <li>Bade + chhote letters dono</li>
            <li>Kam se kam 1 number</li>
            <li>Leaked passwords blocked (HIBP check)</li>
            <li>Must differ from the current password</li>
        </ul>
        <p class="help mt">Yahi policy hosting accounts par bhi lagegi (Step 3+) — Account Panel ki password strength
            settings ka equivalent, aur Step 13 me Server Manager-style se tune hoti hai.</p>
    </div>
</div>
@endsection
