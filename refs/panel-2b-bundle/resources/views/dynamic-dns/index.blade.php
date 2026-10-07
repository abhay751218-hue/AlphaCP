@extends('layouts.panel')

@section('title', 'Dynamic DNS')
@section('subtitle', 'Hosts + tokens — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers add Dynamic DNS hosts here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Dynamic DNS — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/dns/dynamic.json</span>. Public updater later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Name</th>
                <th>Domain</th>
                <th>Token</th>
                <th>IP</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->name }}</td>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="mono">{{ $row->token }}</td>
                    <td class="mono">{{ $row->ip !== '' ? $row->ip : '—' }}</td>
                    <td class="right">
                        @can('dns.manage')
                            <form method="post" action="{{ route('dynamic-dns.destroy', $row) }}" onsubmit="return confirm('Remove this Dynamic DNS host?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No Dynamic DNS hosts yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('dns.manage')
<div class="card mt">
    <h3>Add host</h3>
    <form method="post" action="{{ route('dynamic-dns.store') }}" class="stack">
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
            <input name="name" value="{{ old('name', 'home') }}" maxlength="63" required placeholder="home">
        </label>
        <label>
            IP (optional)
            <input name="ip" value="{{ old('ip') }}" maxlength="15" placeholder="203.0.113.10">
        </label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Add host</button>
    </form>
</div>
@endcan
@endif
@endsection
