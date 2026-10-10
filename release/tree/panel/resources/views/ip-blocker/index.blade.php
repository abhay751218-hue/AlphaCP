@extends('layouts.panel')

@section('title', 'IP Blocker')
@section('subtitle', 'Account ke liye IPs deny karo (ufw/iptables)')

@section('actions')
    <a class="btn small secondary" href="{{ route('privacy.index') }}">Directory Privacy</a>
    <a class="btn small secondary" href="{{ route('ssl.index') }}">SSL/TLS</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'ban', 'cls' => 'hico']) Blocked IPs</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">IPs denied</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Kab use karo</h3>
        <p class="help" style="margin:6px 0 0">Brute-force / spam / scraper IPs ko server level par deny karo —
            firewall (ufw/iptables) rule turant lagta hai.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'ban', 'cls' => 'hico']) Blocked IPs</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search IPs…" data-filter-rows="#acp-ipblock tbody tr" aria-label="Search IPs">
    </div>
    <div class="table-wrap">
        <table id="acp-ipblock">
            <thead><tr><th>IP</th><th>Note</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td><code><span class="badge red">deny</span> {{ $row->ip }}</code></td>
                    <td>{{ $row->note }}</td>
                    <td class="right">
                        <form method="POST" action="{{ route('ip-blocker.destroy', $row) }}" onsubmit="return confirm('Unblock?')">
                            @csrf @method('DELETE')
                            <button class="btn small danger" type="submit">Unblock</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Koi IP blocked nahi hai.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'ban', 'cls' => 'hico']) Block an IP</h3>
    <form method="POST" action="{{ route('ip-blocker.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="ipb-ip">IP address</label>
                <input id="ipb-ip" type="text" name="ip" placeholder="203.0.113.7" required style="min-width:180px">
            </div>
            <div style="flex:1; min-width:200px">
                <label for="ipb-note">Note <span class="muted">(optional)</span></label>
                <input id="ipb-note" type="text" name="note" placeholder="brute-force" maxlength="190">
            </div>
            <button class="btn danger" type="submit">Block IP</button>
        </div>
    </form>
</div>
@endsection
