@extends('layouts.panel')

@section('title', 'Network Tools')
@section('subtitle', 'DNS lookup — kisi bhi domain ke records turant dekho')

@section('actions')
    <a class="btn small secondary" href="{{ route('track-dns.index') }}">Track DNS</a>
    <a class="btn small secondary" href="{{ route('zone-editor.index') }}">Zone Editor</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) DNS Lookup</h3>
        <form method="post" action="{{ route('network-tools.lookup') }}">
            @csrf
            <div class="row" style="flex-wrap:wrap; align-items:flex-end">
                <div style="flex:1; min-width:200px">
                    <label for="nt-query">Domain</label>
                    <input id="nt-query" name="query" required maxlength="190" placeholder="example.com" value="{{ old('query', $query) }}">
                </div>
                <div>
                    <label for="nt-type">Type</label>
                    <select id="nt-type" name="type" style="min-width:110px">
                        @foreach ($types as $t)
                            <option value="{{ $t }}" @selected(old('type', $type ?? 'A') === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn" type="submit">Lookup</button>
            </div>
        </form>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Kya check hota hai</h3>
        <p class="help" style="margin:6px 0 0"><strong>A/AAAA</strong> = IP address · <strong>MX</strong> = mail server ·
            <strong>NS</strong> = nameservers · <strong>TXT</strong> = SPF/DKIM/verification ·
            <strong>CNAME</strong> = alias. Live resolver se query hoti hai — propagation check karne ke liye best.</p>
    </div>
</div>

@if ($error)
<div class="card mt"><p class="empty">{{ $error }}</p></div>
@endif

@if (is_array($records) && $records !== [])
<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Results — <span class="mono">{{ $query }}</span> <span class="badge blue">{{ $type }}</span></h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Filter…" data-filter-rows="#acp-nettools tbody tr" aria-label="Filter records">
    </div>
    <div class="table-wrap">
        <table id="acp-nettools">
            <thead><tr><th>Host</th><th>Type</th><th>TTL</th><th>Priority</th><th>Value</th></tr></thead>
            <tbody>
            @foreach ($records as $r)
                <tr>
                    <td class="mono">{{ $r['host'] }}</td>
                    <td><span class="badge blue">{{ $r['type'] }}</span></td>
                    <td class="mono">{{ $r['ttl'] }}</td>
                    <td class="mono">{{ $r['prio'] ?? '—' }}</td>
                    <td class="mono" style="word-break:break-all">{{ $r['value'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
