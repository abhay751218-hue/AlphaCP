@extends('layouts.panel')

@section('title', 'Park a Domain')
@section('subtitle', 'WHM DNS park — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Parked domains</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/dns/parked.json</span>. BIND later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>Target</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="mono">{{ $row->target }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No parked domains yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Park a domain</h3>
    <form method="post" action="{{ route('park-domain.store') }}" class="stack">
        @csrf
        <label>
            Domain
            <input name="domain" value="{{ old('domain') }}" maxlength="190" required placeholder="alias.example.com">
        </label>
        <label>
            Target
            <input name="target" value="{{ old('target') }}" maxlength="190" required placeholder="example.com">
        </label>
        @error('domain')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Park domain</button>
    </form>
</div>
@endcan
@endsection
