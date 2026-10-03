@extends('layouts.panel')

@section('title', 'Nameserver Record Report')
@section('subtitle', 'WHM NS report — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Nameserver records</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/dns/ns-report.json</span>. BIND later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>Nameserver</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="mono">{{ $row->nameserver }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No nameserver records yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Add nameserver row</h3>
    <form method="post" action="{{ route('ns-report.store') }}" class="stack">
        @csrf
        <label>
            Domain
            <input name="domain" value="{{ old('domain') }}" maxlength="190" required placeholder="example.com">
        </label>
        <label>
            Nameserver
            <input name="nameserver" value="{{ old('nameserver') }}" maxlength="190" required placeholder="ns1.example.com">
        </label>
        @error('domain')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save nameserver</button>
    </form>
</div>
@endcan
@endsection
