@extends('layouts.panel')

@section('title', 'Zone Editor')
@section('subtitle', 'A / CNAME / MX / TXT — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers edit DNS records here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Zone Editor — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/dns/zone.json</span>. BIND later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Value</th>
                <th>Domain</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->name }}</td>
                    <td class="mono">{{ $row->type }}</td>
                    <td class="mono">{{ $row->value }}</td>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="right">
                        @can('dns.manage')
                            <form method="post" action="{{ route('zone-editor.destroy', $row) }}" onsubmit="return confirm('Remove this DNS record?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No DNS records yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('dns.manage')
<div class="card mt">
    <h3>Add record</h3>
    <form method="post" action="{{ route('zone-editor.store') }}" class="stack">
        @csrf
        <label>
            Domain
            <select name="domain" required>
                @forelse ($domains as $domain)
                    <option value="{{ $domain }}" @selected(old('domain') === $domain)>{{ $domain }}</option>
                @empty
                    <option value="" disabled>No domain</option>
                @endforelse
            </select>
        </label>
        <label>
            Name
            <input name="name" value="{{ old('name', 'www') }}" maxlength="63" required placeholder="www">
        </label>
        <label>
            Type
            <select name="type" required>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected(old('type', 'A') === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Value
            <input name="value" value="{{ old('value') }}" maxlength="255" required placeholder="203.0.113.10">
        </label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Add record</button>
    </form>
</div>
@endcan
@endif
@endsection
