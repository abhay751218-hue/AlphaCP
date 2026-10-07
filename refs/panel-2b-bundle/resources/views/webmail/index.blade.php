@extends('layouts.panel')

@section('panel-theme', 'webmail')
@section('title', 'Webmail')
@section('subtitle', 'Roundcube/Horde client preference — no install, no SSO, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers set Webmail here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Webmail — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/webmail.json</span>. Roundcube/Horde app later. Pipe/shell fail closed.</p>
    <p>Status: <strong>{{ $enabled ? 'on' : 'off' }}</strong> · client: <span class="mono">{{ $client }}</span></p>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Update</h3>
    <form method="post" action="{{ route('webmail.store') }}">
        @csrf
        <label for="enabled">Enabled</label>
        <select id="enabled" name="enabled">
            <option value="0" @selected(! $enabled)>off</option>
            <option value="1" @selected($enabled)>on</option>
        </select>
        <label for="client">Client</label>
        <select id="client" name="client" required>
            <option value="roundcube" @selected(old('client', $client) === 'roundcube')>roundcube</option>
            <option value="horde" @selected(old('client', $client) === 'horde')>horde</option>
        </select>
        <button class="btn mt" type="submit">Save Webmail</button>
    </form>
</div>
@endcan
@endif
@endsection
