@extends('layouts.panel')

@section('title', 'Track Delivery')
@section('subtitle', 'Search delivery events by recipient — no Exim log, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers search delivery traces here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Track Delivery — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/track.json</span>. Exim mainlog later. Query email only — pipe/shell fail closed.</p>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Search</h3>
    <form method="post" action="{{ route('track-delivery.store') }}">
        @csrf
        <label for="query">Recipient email</label>
        <input id="query" name="query" required maxlength="190" placeholder="alice@example.net" value="{{ old('query') }}">
        <button class="btn mt" type="submit">Track</button>
    </form>
</div>
@endcan
@endif
@endsection
