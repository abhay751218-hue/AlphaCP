@extends('layouts.panel')

@section('title', 'Zone Editor')
@section('subtitle', 'A / CNAME / MX / TXT records — declarative, safe sync')

@section('actions')
    <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers edit DNS records here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else

{{-- stats strip --}}
<div class="grid cols-3">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) DNS records</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">/ 50 allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Zones</h3>
        <div class="stat"><span class="num">{{ count($domains) }}</span>
            <span class="unit">{{ implode(', ', array_slice($domains, 0, 2)) }}{{ count($domains) > 2 ? '…' : '' }}</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'list', 'cls' => 'hico']) By type</h3>
        <p class="help" style="margin:6px 0 0">
            @foreach ($types as $t)
                <span class="badge green">{{ $t }} × {{ $rows->where('type', $t)->count() }}</span>
            @endforeach
        </p>
    </div>
</div>

{{-- records list --}}
<div class="card mt">
    <div class="row mb" style="flex-wrap:wrap">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Zone Records — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search records (name, value, type)…"
               data-filter-rows="#acp-zone tbody tr" aria-label="Search DNS records">
    </div>
    <div class="table-wrap">
        <table id="acp-zone">
            <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Value</th>
                <th>Zone</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->name }}</td>
                    <td><span class="badge blue">{{ $row->type }}</span></td>
                    <td class="mono" style="word-break:break-all">{{ $row->value }}</td>
                    <td class="mono muted">{{ $row->domain }}</td>
                    <td class="right">
                        @can('dns.manage')
                            <form method="post" action="{{ route('zone-editor.destroy', $row) }}" onsubmit="return confirm('Remove {{ $row->name }}.{{ $row->domain }} ({{ $row->type }})?')" style="display:inline">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
                @can('dns.manage')
                <tr class="acp-subrow">
                    <td colspan="5">
                        <details class="acp-exp">
                            <summary>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Edit record</summary>
                            <form method="post" action="{{ route('zone-editor.update', $row) }}" class="acp-exp-body">
                                @csrf
                                @method('PUT')
                                <div class="row" style="flex-wrap:wrap">
                                    <div>
                                        <label for="zn-{{ $row->id }}">Name</label>
                                        <input id="zn-{{ $row->id }}" name="name" required maxlength="63" value="{{ $row->name }}" style="width:150px">
                                    </div>
                                    <div style="flex:1; min-width:200px">
                                        <label for="zv-{{ $row->id }}">Value ({{ $row->type }})</label>
                                        <input id="zv-{{ $row->id }}" name="value" required maxlength="255" value="{{ $row->value }}">
                                    </div>
                                </div>
                                <p class="help">Type ({{ $row->type }}) aur zone ({{ $row->domain }}) fixed hain — type badalna ho to delete karke naya banao.</p>
                                <button class="btn small mt" type="submit">Save record</button>
                            </form>
                        </details>
                    </td>
                </tr>
                @endcan
            @empty
                <tr><td colspan="5" class="empty">No DNS records yet — neeche se pehla record banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Records JSON <span class="mono">~/etc/dns/zone.json</span> me declaratively sync hote hain. Pipe/shell fail-closed. TTL/MX-priority agle update me.</p>
</div>

{{-- add record (cPanel Add Record) --}}
@can('dns.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Add Record</h3>
    <form method="post" action="{{ route('zone-editor.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="zone-domain">Zone (domain)</label>
                <select id="zone-domain" name="domain" required>
                    @forelse ($domains as $domain)
                        <option value="{{ $domain }}" @selected(old('domain') === $domain)>{{ $domain }}</option>
                    @empty
                        <option value="" disabled>No domain</option>
                    @endforelse
                </select>
                <label for="zone-name">Name</label>
                <input id="zone-name" name="name" value="{{ old('name', 'www') }}" maxlength="63" required placeholder="www">
                <p class="help"><span class="mono">@</span> = zone root · <span class="mono">*</span> = wildcard · ya label jaise <span class="mono">www</span></p>
            </div>
            <div>
                <label for="zone-type">Type</label>
                <select id="zone-type" name="type" required data-zonetype>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(old('type', 'A') === $type)>{{ $type }}</option>
                    @endforeach
                </select>
                <label for="zone-value">Value</label>
                <input id="zone-value" name="value" value="{{ old('value') }}" maxlength="255" required placeholder="203.0.113.10"
                       data-zonevalue
                       data-ph-a="203.0.113.10 (IPv4 address)"
                       data-ph-cname="target.example.com"
                       data-ph-aaaa="2001:db8::1 (IPv6 address)"
                       data-ph-mx="10 mail.example.com (priority + host)"
                       data-ph-txt="v=spf1 a mx -all">
                <button class="btn mt" type="submit">+ Add Record</button>
            </div>
        </div>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <p class="help">MX value me priority optional hai — <span class="mono">10 mail.example.com</span>. AAAA = IPv6 (D14).</p>
    </form>
</div>
@endcan
@endif
@endsection
