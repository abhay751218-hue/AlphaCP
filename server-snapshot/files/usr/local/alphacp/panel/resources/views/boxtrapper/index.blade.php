@extends('layouts.panel')

@section('title', 'BoxTrapper')
@section('subtitle', 'Challenge-response allowlist — no daemon, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apna BoxTrapper yahin set karega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>BoxTrapper — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/boxtrapper.json</span>. Challenge daemon later. Pipe/shell fail closed.</p>
    <p>Status: <strong>{{ $enabled ? 'on' : 'off' }}</strong></p>
    <div class="table-wrap mt">
        <table>
            <tr><th>Allowlist</th></tr>
            @forelse ($allowlist as $addr)
                <tr><td class="mono">{{ $addr }}</td></tr>
            @empty
                <tr><td class="empty">Khaali</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Update</h3>
    <form method="post" action="{{ route('boxtrapper.store') }}">
        @csrf
        <label for="enabled">Enabled</label>
        <select id="enabled" name="enabled">
            <option value="0" @selected(! $enabled)>off</option>
            <option value="1" @selected($enabled)>on</option>
        </select>
        <label for="dest">Allowlist email (optional)</label>
        <input id="dest" name="dest" maxlength="190" placeholder="alice@example.net" value="{{ old('dest') }}">
        <button class="btn mt" type="submit">Save BoxTrapper</button>
    </form>
</div>
@endcan
@endif
@endsection
