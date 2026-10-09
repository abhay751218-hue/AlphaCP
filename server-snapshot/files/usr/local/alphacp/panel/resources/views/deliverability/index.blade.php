@extends('layouts.panel')

@section('title', 'Email Deliverability')
@section('subtitle', 'SPF, DKIM aur DMARC — mail inbox tak pahunchane wale DNS records')

@section('actions')
    <a class="btn small secondary" href="{{ route('zone-editor.index') }}">Zone Editor</a>
    <a class="btn small secondary" href="{{ route('track-delivery.index') }}">Track Delivery</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

@if (empty($rows))
<div class="card"><p class="empty">No domains on this account.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico']) Domains</h3>
        <div class="stat"><span class="num">{{ count($rows) }}</span><span class="unit">domains checked</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Records kya karte hain</h3>
        <p class="help" style="margin:6px 0 0"><strong>SPF</strong> = kaun se servers mail bhej sakte hain ·
            <strong>DKIM</strong> = mail par crypto signature · <strong>DMARC</strong> = fail hone par kya karna hai.
            Teeno sahi ho to mail spam me nahi jati.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico']) Recommended Records — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search domains…" data-filter-rows="#acp-deliv tbody tr" aria-label="Search domains">
    </div>
    <div class="table-wrap">
        <table id="acp-deliv">
            <thead><tr><th>Domain</th><th>SPF</th><th>DKIM selector</th><th>DMARC</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row['domain'] }}</td>
                    <td class="mono" style="max-width:280px; word-break:break-all"><span class="badge blue">TXT</span> {{ $row['spf'] }}</td>
                    <td class="mono"><span class="badge green">DKIM</span> {{ $row['dkim_selector'] }}</td>
                    <td class="mono" style="max-width:280px; word-break:break-all"><span class="badge amber">TXT</span> {{ $row['dmarc'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No domains on this account.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Ye records <a href="{{ route('zone-editor.index') }}">Zone Editor</a> me TXT records ke roop me add karo (external DNS use karte ho to wahan).</p>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Queue recommended records</h3>
    <form method="post" action="{{ route('deliverability.store') }}">
        @csrf
        <div class="row" style="align-items:center; flex-wrap:wrap">
            <button class="btn" type="submit">Write deliverability.json</button>
            <span class="help" style="margin:0">Recommended records server par <span class="mono">deliverability.json</span> me save hote hain, taake mail stack unhe apply kar sake.</span>
        </div>
    </form>
</div>
@endcan
@endif
@endif
@endsection
