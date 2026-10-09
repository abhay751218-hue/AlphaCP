@extends('layouts.panel')

@section('title', 'Setup/Edit Domain Forwarding')
@section('subtitle', 'Domain forward — no BIND rewrite, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
    <a class="btn small secondary" href="{{ route('zone-editor.index') }}">Zone Editor</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Domain forwards</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">forwards configured</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Redirect codes</h3>
        <p class="help" style="margin:6px 0 0"><strong>301</strong> = permanent (SEO transfer) ·
            <strong>302</strong> = temporary. JSON <span class="mono">/usr/local/alphacp/etc/dns/forward.json</span> —
            pipe/shell fail-closed.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Current Forwards</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-dforward tbody tr" aria-label="Search forwards">
    </div>
    <div class="table-wrap">
        <table id="acp-dforward">
            <thead><tr><th>Domain</th><th>URL</th><th>Code</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td class="mono">→ {{ $row->url }}</td>
                    <td><span class="badge {{ (string) $row->code === '301' ? 'green' : 'blue' }}">{{ $row->code }}</span></td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No domain forwards yet — neeche se set karo.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('accounts.view')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Set Forward</h3>
    <form method="post" action="{{ route('domain-forward.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div style="flex:1; min-width:180px">
                <label for="df-domain">Domain</label>
                <input id="df-domain" name="domain" value="{{ old('domain') }}" maxlength="190" required placeholder="old.example.com">
            </div>
            <div style="flex:1; min-width:200px">
                <label for="df-url">URL</label>
                <input id="df-url" name="url" value="{{ old('url') }}" maxlength="255" required placeholder="https://example.com">
            </div>
            <div>
                <label for="df-code">Code</label>
                <select id="df-code" name="code" required style="min-width:100px">
                    @foreach ($codes as $code)
                        <option value="{{ $code }}" @selected((string) old('code', '301') === (string) $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn" type="submit">Save Forward</button>
        </div>
        @error('domain')<p class="error">{{ $message }}</p>@enderror
    </form>
</div>
@endcan
@endsection
