@extends('layouts.panel')

@section('title', 'SSL/TLS Status')
@section('subtitle', "AutoSSL — Let's Encrypt (HTTP-01) · self-signed fallback")

@section('actions')
    @can('domains.view')
        <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
    @endcan
    @if ($account && $panelMode !== 'whm')
        @can('ssl.manage')
            <form method="post" action="{{ route('ssl.autossl') }}" style="display:inline">
                @csrf
                <button class="btn small" type="submit">🔒 Run AutoSSL</button>
            </form>
        @endcan
    @endif
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customer AutoSSL (Let's Encrypt) apne domains par chalayega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
@php
    $sslActive = $domains->where('ssl_status', 'active')->count();
    $sslFailed = $domains->where('ssl_status', 'failed')->count();
    $expSoon = $domains->filter(fn ($d) => $d->ssl_not_after !== null && $d->ssl_not_after->lte(now()->addDays(21)))->count();
@endphp

{{-- stats strip --}}
<div class="grid cols-3">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'lock', 'cls' => 'hico']) Secured</h3>
        <div class="stat"><span class="num">{{ $sslActive }}</span>
            <span class="unit">/ {{ $domains->count() }} domains</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'alert', 'cls' => 'hico']) Attention</h3>
        <div class="stat"><span class="num">{{ $sslFailed + $expSoon }}</span>
            <span class="unit">{{ $sslFailed }} failed · {{ $expSoon }} expiring ≤ 21 din</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'refresh', 'cls' => 'hico']) AutoSSL</h3>
        <p class="help" style="margin:6px 0 0">"Run AutoSSL" sab included domains par Let's Encrypt try karta hai.
            Renewals automatic hain. DNS is server par point hona chahiye (HTTP-01).</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico']) Certificates — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search domains…"
               data-filter-rows="#acp-ssl tbody tr" aria-label="Search SSL domains">
    </div>
    <div class="table-wrap">
        <table id="acp-ssl">
            <thead>
            <tr>
                <th>Domain</th>
                <th>Type</th>
                <th>SSL</th>
                <th>Issuer</th>
                <th>Expires</th>
                <th>AutoSSL</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($domains as $row)
                @php
                    $daysLeft = $row->ssl_not_after !== null ? (int) now()->diffInDays($row->ssl_not_after, false) : null;
                @endphp
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td><span class="badge blue">{{ $row->type }}</span></td>
                    <td>
                        <span class="badge {{ ($row->ssl_status ?? 'none') === 'active' ? 'green' : ((($row->ssl_status ?? '') === 'failed') ? 'red' : 'amber') }}">{{ $row->ssl_status ?? 'none' }}</span>
                        @if ($row->ssl_last_error)
                            <div class="muted" style="max-width:18rem; font-size:12px">{{ $row->ssl_last_error }}</div>
                        @endif
                    </td>
                    <td class="muted">{{ $row->ssl_issuer ?? '—' }}</td>
                    <td>
                        @if ($row->ssl_not_after)
                            <span class="{{ $daysLeft !== null && $daysLeft <= 21 ? 'mono' : 'muted' }}">{{ $row->ssl_not_after->toDateString() }}</span>
                            @if ($daysLeft !== null && $daysLeft <= 21)
                                <span class="badge {{ $daysLeft <= 7 ? 'red' : 'amber' }}">{{ $daysLeft }} din</span>
                            @endif
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td>
                        @can('ssl.manage')
                            <form method="post" action="{{ route('ssl.toggle', $row) }}" style="display:inline">
                                @csrf
                                <button class="btn small secondary" type="submit">{{ $row->ssl_autossl ? 'include' : 'exclude' }}</button>
                            </form>
                        @else
                            <span class="muted">{{ $row->ssl_autossl ? 'include' : 'exclude' }}</span>
                        @endcan
                    </td>
                    <td class="right">
                        @can('ssl.manage')
                            @if ($row->type !== 'redirect' && ! in_array($row->ssl_status, ['pending', 'removing'], true))
                                <form method="post" action="{{ route('ssl.issue', $row) }}" style="display:inline">
                                    @csrf
                                    <input type="hidden" name="mode" value="letsencrypt">
                                    <button class="btn small" type="submit">Let's Encrypt</button>
                                </form>
                                @if (($row->ssl_status ?? 'none') !== 'active')
                                    <form method="post" action="{{ route('ssl.issue', $row) }}" style="display:inline">
                                        @csrf
                                        <input type="hidden" name="mode" value="selfsigned">
                                        <button class="btn small secondary" type="submit">self-signed</button>
                                    </form>
                                @endif
                            @endif
                            @if (($row->ssl_status ?? 'none') === 'active')
                                <form method="post" action="{{ route('ssl.destroy', $row) }}" style="display:inline" onsubmit="return confirm('Remove this SSL vhost?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn small danger" type="submit">remove</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Add a domain first.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Let's Encrypt free hai — DNS yahan point karo, phir "Run AutoSSL". Self-signed = browser warning (testing only).</p>
</div>
@endif
@endsection
