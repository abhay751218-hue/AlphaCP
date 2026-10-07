@extends('layouts.panel')

@section('title', 'Track DNS')
@section('subtitle', 'Search zone/dynamic JSON by FQDN — no dig, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers trace DNS here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Track DNS — {{ $account->username }}</h3>
    <p class="help">Reads <span class="mono">~/etc/dns/zone.json</span> and <span class="mono">dynamic.json</span>. Dig/BIND later. Pipe/shell fail closed.</p>
</div>

@can('dns.view')
<div class="card mt">
    <h3>Trace</h3>
    <form method="post" action="{{ route('track-dns.store') }}" class="stack">
        @csrf
        <label>
            Query
            <input name="query" required maxlength="190" placeholder="www.shop.example.com" value="{{ old('query') }}">
        </label>
        <label>
            Type
            <select name="type" required>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected(old('type', 'ALL') === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </label>
        @error('query')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Track</button>
    </form>
</div>
@endcan
@endif
@endsection
