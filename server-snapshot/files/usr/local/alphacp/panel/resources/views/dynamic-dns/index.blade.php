@extends('layouts.panel')

@section('title', 'Dynamic DNS')
@section('subtitle', 'Hosts + tokens — ghar/office ke badalte IP ke liye')

@section('actions')
    <a class="btn small secondary" href="{{ route('zone-editor.index') }}">Zone Editor</a>
    <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Dynamic DNS hosts</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">hosts configured</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Token kya hai</h3>
        <p class="help" style="margin:6px 0 0">Har host ka secret token hota hai — updater us token se naya IP
            report karta hai. JSON <span class="mono">~/etc/dns/dynamic.json</span>, pipe/shell fail-closed.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Hosts — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search hosts…" data-filter-rows="#acp-ddns tbody tr" aria-label="Search hosts">
    </div>
    <div class="table-wrap">
        <table id="acp-ddns">
            <thead><tr><th>Name</th><th>Domain</th><th>Token</th><th>IP</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->name }}</td>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="mono">{{ $row->token }}</td>
                    <td>@if ($row->ip !== '')<span class="badge green mono">{{ $row->ip }}</span>@else<span class="badge gray">no IP yet</span>@endif</td>
                    <td class="right">
                        @can('dns.manage')
                            <form method="post" action="{{ route('dynamic-dns.destroy', $row) }}" onsubmit="return confirm('Remove this Dynamic DNS host?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No Dynamic DNS hosts yet — neeche se add karo.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('dns.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Add Host</h3>
    <form method="post" action="{{ route('dynamic-dns.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="ddns-domain">Domain</label>
                <select id="ddns-domain" name="domain" required style="min-width:180px">
                    @forelse ($domains as $domain)
                        <option value="{{ $domain }}" @selected(old('domain') === $domain)>{{ $domain }}</option>
                    @empty
                        <option value="" disabled>No domain</option>
                    @endforelse
                </select>
            </div>
            <div>
                <label for="ddns-name">Name</label>
                <input id="ddns-name" name="name" value="{{ old('name', 'home') }}" maxlength="63" required placeholder="home" style="min-width:140px">
            </div>
            <div>
                <label for="ddns-ip">IP <span class="muted">(optional)</span></label>
                <input id="ddns-ip" name="ip" value="{{ old('ip') }}" maxlength="15" placeholder="203.0.113.10" style="min-width:150px">
            </div>
            <button class="btn" type="submit">+ Add Host</button>
        </div>
        @error('name')<p class="error">{{ $message }}</p>@enderror
    </form>
</div>
@endcan
@endif
@endsection
