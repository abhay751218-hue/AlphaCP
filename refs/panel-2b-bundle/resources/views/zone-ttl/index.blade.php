@extends('layouts.panel')

@section('title', 'Set Zone TTL')
@section('subtitle', 'WHM zone TTL — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Zone TTL</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/dns/ttl.json</span>. BIND later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>TTL</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="mono">{{ $row->ttl }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No zone TTLs yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Set TTL</h3>
    <form method="post" action="{{ route('zone-ttl.store') }}" class="stack">
        @csrf
        <label>
            Domain
            <input name="domain" value="{{ old('domain') }}" maxlength="190" required placeholder="example.com">
        </label>
        <label>
            TTL (seconds)
            <select name="ttl" required>
                @foreach ($ttls as $ttl)
                    <option value="{{ $ttl }}" @selected((string) old('ttl', '3600') === (string) $ttl)>{{ $ttl }}</option>
                @endforeach
            </select>
        </label>
        @error('domain')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save TTL</button>
    </form>
</div>
@endcan
@endsection
