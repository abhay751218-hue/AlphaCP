@extends('layouts.panel')

@section('title', 'Synchronize DNS Records')
@section('subtitle', 'WHM zone sync — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>DNS sync queue</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/dns/sync.json</span>. BIND later. Pipe/shell fail closed.</p>
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
                <tr><td class="empty">No sync domains yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Queue domain</h3>
    <form method="post" action="{{ route('dns-sync.store') }}" class="stack">
        @csrf
        <label>
            Domain
            <input name="domain" value="{{ old('domain') }}" maxlength="190" required placeholder="example.com">
        </label>
        @error('domain')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue sync</button>
    </form>
</div>
@endcan
@endsection
