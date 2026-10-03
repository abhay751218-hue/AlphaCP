@extends('layouts.panel')

@section('title', 'Perform a DNS Cleanup')
@section('subtitle', 'WHM stale zones — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>DNS cleanup queue</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/dns/cleanup.json</span>. BIND later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                </tr>
            @empty
                <tr><td class="empty">No cleanup domains yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Queue domain</h3>
    <form method="post" action="{{ route('dns-cleanup.store') }}" class="stack">
        @csrf
        <label>
            Domain
            <input name="domain" value="{{ old('domain') }}" maxlength="190" required placeholder="stale.example.com">
        </label>
        @error('domain')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue cleanup</button>
    </form>
</div>
@endcan
@endsection
