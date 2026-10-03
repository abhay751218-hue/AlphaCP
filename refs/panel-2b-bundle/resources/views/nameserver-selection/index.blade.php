@extends('layouts.panel')

@section('title', 'Nameserver Selection')
@section('subtitle', 'WHM nameserver — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Nameserver selection</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/dns/nameserver.json</span>. BIND later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->software }} · {{ $row->ns1 }} · {{ $row->ns2 }}</p>
    @else
        <p class="empty">No nameserver selection yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Set nameserver</h3>
    <form method="post" action="{{ route('nameserver-selection.store') }}" class="stack">
        @csrf
        <label>
            Software
            <select name="software" required>
                @foreach ($softwares as $software)
                    <option value="{{ $software }}" @selected(old('software', $row?->software ?? 'bind') === $software)>{{ $software }}</option>
                @endforeach
            </select>
        </label>
        <label>
            NS1
            <input name="ns1" value="{{ old('ns1', $row?->ns1 ?? '') }}" maxlength="190" required placeholder="ns1.example.com">
        </label>
        <label>
            NS2
            <input name="ns2" value="{{ old('ns2', $row?->ns2 ?? '') }}" maxlength="190" required placeholder="ns2.example.com">
        </label>
        @error('software')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save nameserver</button>
    </form>
</div>
@endcan
@endsection
