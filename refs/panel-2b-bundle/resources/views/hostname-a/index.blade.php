@extends('layouts.panel')

@section('title', 'Add an A Entry for Your Hostname')
@section('subtitle', 'Server hostname A — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Hostname A entry</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/dns/hostname.json</span>. BIND later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="help">Current: <span class="mono">{{ $row->hostname }} → {{ $row->ip }}</span></p>
    @else
        <p class="empty">No hostname A entry yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Set A record</h3>
    <form method="post" action="{{ route('hostname-a.store') }}" class="stack">
        @csrf
        <label>
            Hostname
            <input name="hostname" value="{{ old('hostname', $row->hostname ?? '') }}" maxlength="190" required placeholder="server.example.com">
        </label>
        <label>
            IPv4
            <input name="ip" value="{{ old('ip', $row->ip ?? '') }}" maxlength="15" required placeholder="203.0.113.10">
        </label>
        @error('hostname')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save A entry</button>
    </form>
</div>
@endcan
@endsection
