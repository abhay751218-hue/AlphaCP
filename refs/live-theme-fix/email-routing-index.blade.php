@extends('layouts.panel')

@section('title', 'Email Routing')
@section('subtitle', 'Per-domain MX mode — auto / local / backup / remote')

@section('actions')
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
    <a class="btn small secondary" href="{{ route('zone-editor.index') }}">Zone Editor</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Routing rules</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">domains configured (baaki = auto)</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'filter', 'cls' => 'hico']) Modes</h3>
        <p class="help" style="margin:6px 0 0"><strong>auto</strong> = MX dekh kar khud decide ·
            <strong>local</strong> = mail yahi deliver hogi · <strong>backup</strong> = primary down ho to hold ·
            <strong>remote</strong> = mail bahar (Google/M365) jati hai, yahan accept nahi.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Current Routing — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-routing tbody tr" aria-label="Search routing">
    </div>
    <div class="table-wrap">
        <table id="acp-routing">
            <thead><tr><th>Domain</th><th>Mode</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td><span class="badge {{ $row->mode === 'local' ? 'green' : ($row->mode === 'remote' ? 'amber' : 'blue') }}">{{ $row->mode }}</span></td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">Koi custom routing nahi — sab domains <span class="badge blue">auto</span> par hain.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Set Routing</h3>
    <form method="post" action="{{ route('email-routing.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="domain">Domain</label>
                <select id="domain" name="domain" required style="min-width:200px">
                    @forelse ($domains as $d)
                        <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
                    @empty
                        <option value="" disabled>No domain</option>
                    @endforelse
                </select>
            </div>
            <div>
                <label for="mode">Mode</label>
                <select id="mode" name="mode" required style="min-width:160px">
                    @foreach ($modes as $m)
                        <option value="{{ $m }}" @selected(old('mode', 'auto') === $m)>{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn" type="submit">Save Routing</button>
        </div>
        <p class="help">Google Workspace / Microsoft 365 use kar rahe ho to <strong>remote</strong> chuno (MX unki taraf ho).</p>
    </form>
</div>
@endcan
@endif
@endsection
