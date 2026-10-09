@extends('layouts.panel')

@section('title', 'Domains')
@section('subtitle', 'Main, addon, subdomain, alias (parked) aur redirects')

@section('actions')
    <a class="btn small secondary" href="{{ route('zone-editor.index') }}">Zone Editor</a>
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')

@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Create the account in Server Manager; the customer manages domains here.</p>
    <p class="help mt">Accounts page: <a href="{{ route('accounts.index') }}">List Accounts →</a></p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login. Domains appear here after a hosting account is created.</p>
</div>
@else
@php
    $byType = $domains->groupBy('type');
    $pkg = $account->package;
@endphp

{{-- stats strip (cPanel top) --}}
<div class="grid cols-3">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Addon domains</h3>
        <div class="stat"><span class="num">{{ ($byType['addon'] ?? collect())->count() }}</span>
            <span class="unit">/ {{ $pkg?->formatLimit('MAXADDON') ?? '—' }} allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'dns', 'cls' => 'hico']) Subdomains</h3>
        <div class="stat"><span class="num">{{ ($byType['sub'] ?? collect())->count() }}</span>
            <span class="unit">/ {{ $pkg?->formatLimit('MAXSUB') ?? '—' }} allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Aliases + redirects</h3>
        <div class="stat"><span class="num">{{ ($byType['parked'] ?? collect())->count() + ($byType['redirect'] ?? collect())->count() }}</span>
            <span class="unit">/ {{ $pkg?->formatLimit('MAXPARK') ?? '—' }} allowed</span></div>
    </div>
</div>

{{-- list --}}
<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Domains — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search domains…"
               data-filter-rows="#acp-domains tbody tr" aria-label="Search domains">
    </div>
    <div class="table-wrap">
        <table id="acp-domains">
            <thead>
            <tr>
                <th>Domain</th>
                <th>Type</th>
                <th>Document root / redirect</th>
                <th>Status</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($domains as $row)
                <tr>
                    <td>
                        <span class="mono">{{ $row->domain }}</span>
                        @if ($row->isMain())
                            <span class="badge blue">main</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $row->type === 'main' ? 'blue' : ($row->type === 'redirect' ? 'amber' : 'green') }}">{{ $row->type }}</span></td>
                    <td class="muted mono" style="font-size:12px">
                        @if ($row->type === 'redirect')
                            {{ $row->redirect_code }} → {{ $row->redirect_url }}
                        @else
                            {{ $row->document_root }}
                        @endif
                    </td>
                    <td><span class="badge {{ $row->status === 'active' ? 'green' : 'amber' }}">{{ $row->status }}</span></td>
                    <td class="right">
                        <div class="row" style="justify-content:flex-end">
                            @if ($row->type !== 'redirect')
                                <a class="btn small secondary" href="https://{{ $row->domain }}" target="_blank" rel="noopener">Visit ↗</a>
                            @endif
                            @can('domains.manage')
                                @if (! $row->isMain() && $row->status !== 'removing')
                                    <form method="post" action="{{ route('domains.destroy', $row) }}" onsubmit="return confirm('Remove {{ $row->domain }}? Files delete NAHI hongi.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn small danger" type="submit">Delete</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No domains yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">DNS records edit karne ke liye <a href="{{ route('zone-editor.index') }}">Zone Editor</a> kholo.</p>
</div>

{{-- create (cPanel "Create a New Domain") --}}
@can('domains.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Create a New Domain</h3>
    <form method="post" action="{{ route('domains.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label>Type</label>
                <div class="row" style="flex-wrap:wrap; gap:10px">
                    <label class="check" style="margin:0"><input type="radio" name="type" value="addon" checked data-domtype> Addon</label>
                    <label class="check" style="margin:0"><input type="radio" name="type" value="sub" data-domtype> Subdomain</label>
                    <label class="check" style="margin:0"><input type="radio" name="type" value="parked" data-domtype> Alias (parked)</label>
                    <label class="check" style="margin:0"><input type="radio" name="type" value="redirect" data-domtype> Redirect</label>
                </div>
                <p class="help" data-domtype-hint
                   data-hint-addon="Addon = bilkul alag website apne docroot ke saath (example.net)."
                   data-hint-sub="Subdomain = parent ke neeche, jaise blog.{{ $account->main_domain }}."
                   data-hint-parked="Alias = parked domain, main site ki copy serve karta hai."
                   data-hint-redirect="Redirect = visitors ko doosre URL par 301/302 bhejta hai.">Addon = bilkul alag website apne docroot ke saath (example.net).</p>

                <label for="domain">Domain (FQDN)</label>
                <input id="domain" name="domain" required maxlength="190" placeholder="example.net" autocapitalize="none" value="{{ old('domain') }}" data-main="{{ $account->main_domain }}">
            </div>
            <div data-redirect-fields hidden>
                <label for="redirect_url">Redirect URL</label>
                <input id="redirect_url" name="redirect_url" maxlength="500" placeholder="https://example.com/" value="{{ old('redirect_url') }}">
                <label for="redirect_code">Redirect code</label>
                <select id="redirect_code" name="redirect_code">
                    <option value="301">301 — Permanent</option>
                    <option value="302">302 — Temporary</option>
                </select>
            </div>
        </div>
        <button class="btn mt" type="submit">+ Create Domain</button>
    </form>
    <p class="help">Document root auto: addon <span class="mono">~/&lt;fqdn&gt;/public_html</span> · subdomain <span class="mono">~/public_html/&lt;label&gt;</span> · alias/redirect <span class="mono">~/public_html</span>. Limits package se (MAXADDON/MAXSUB/MAXPARK).</p>
</div>
@endcan
@endif

@endsection
