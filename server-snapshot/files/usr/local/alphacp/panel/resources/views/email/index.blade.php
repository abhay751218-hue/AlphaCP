@extends('layouts.panel')

@section('title', 'Email Accounts')
@section('subtitle', 'Create, manage and connect mailboxes — cPanel-style')

@section('actions')
    <a class="btn small secondary" href="{{ route('webmail.index') }}">Check Email</a>
    <a class="btn small secondary" href="{{ route('email-disk.index') }}">Email Disk Usage</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers create mailboxes here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else

{{-- ============================== stats row (cPanel top strip) --}}
<div class="grid cols-3">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'mail', 'cls' => 'hico']) Email accounts</h3>
        <div class="stat"><span class="num">{{ $boxes->count() }}</span>
            <span class="unit">/ {{ $maxPop }} allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'hdd', 'cls' => 'hico']) Mail disk used</h3>
        <div class="stat"><span class="num">{{ array_sum($usage) }}</span><span class="unit">MB (listed boxes)</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'globe', 'cls' => 'hico']) Mail domains</h3>
        <div class="stat"><span class="num">{{ count($domains) }}</span>
            <span class="unit">{{ implode(', ', array_slice($domains, 0, 2)) }}{{ count($domains) > 2 ? '…' : '' }}</span></div>
    </div>
</div>

{{-- ============================== list + per-box manage --}}
<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'mail', 'cls' => 'hico']) Email Accounts — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search email accounts…"
               data-filter-rows="#acp-mailboxes tbody tr" aria-label="Search email accounts">
    </div>
    <div class="table-wrap">
        <table id="acp-mailboxes">
            <thead>
            <tr>
                <th>Account</th>
                <th>Restrictions</th>
                <th style="min-width:190px">Storage</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($boxes as $box)
                @php
                    $used = $usage[$box->address()] ?? null;
                    $pct = ($used !== null && $box->quota_mb > 0) ? min(100, (int) round($used * 100 / $box->quota_mb)) : null;
                @endphp
                <tr>
                    <td>
                        <span class="mono">{{ $box->address() }}</span>
                        @if ($box->status !== 'active')
                            <span class="badge amber" title="agent sync pending">{{ $box->status }}</span>
                        @endif
                    </td>
                    <td><span class="badge green">Unrestricted</span></td>
                    <td>
                        @if ($used !== null)
                            <span class="mono">{{ $used }} MB</span>
                            <span class="muted">/ {{ $box->quota_mb < 0 ? '∞' : $box->quota_mb . ' MB' }}</span>
                            @if ($pct !== null)
                                <div class="meter {{ $pct > 85 ? 'amber' : 'green' }}" style="margin-top:6px"><span style="width: {{ max(2, $pct) }}%"></span></div>
                            @endif
                        @else
                            <span class="muted">{{ $box->quota_mb < 0 ? 'unlimited' : $box->quota_mb . ' MB quota' }}</span>
                        @endif
                    </td>
                    <td class="right">
                        <div class="row" style="justify-content:flex-end">
                            <a class="btn small secondary" href="{{ route('webmail.index') }}">Check Email</a>
                            @can('email.manage')
                                <form method="post" action="{{ route('email.destroy', $box) }}" onsubmit="return confirm('Remove {{ $box->address() }}? Maildir bhi delete hogi.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn small danger" type="submit">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
                <tr class="acp-subrow">
                    <td colspan="4">
                        <div class="row" style="gap:14px; align-items:flex-start; flex-wrap:wrap">
                            @can('email.manage')
                            <details class="acp-exp">
                                <summary>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Manage (quota / password)</summary>
                                <form method="post" action="{{ route('email.update', $box) }}" class="acp-exp-body">
                                    @csrf
                                    @method('PUT')
                                    <label>Storage</label>
                                    <div class="row">
                                        <label class="check" style="margin:0"><input type="radio" name="quota_mode" value="limited" {{ $box->quota_mb >= 0 ? 'checked' : '' }} data-quota-radio> Space (MB)</label>
                                        <input type="number" name="quota_mb" min="1" max="102400" value="{{ $box->quota_mb >= 0 ? $box->quota_mb : 1024 }}" style="width:120px" {{ $box->quota_mb < 0 ? 'disabled' : '' }}>
                                        <label class="check" style="margin:0"><input type="radio" name="quota_mode" value="unlimited" {{ $box->quota_mb < 0 ? 'checked' : '' }} data-quota-radio> Unlimited</label>
                                    </div>
                                    <label for="pw-{{ $box->id }}">New password <span class="muted">(blank = no change)</span></label>
                                    <div class="row" data-pw>
                                        <input id="pw-{{ $box->id }}" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" style="flex:1; min-width:180px">
                                        <button class="btn small secondary" type="button" data-pw-show>Show</button>
                                        <button class="btn small secondary" type="button" data-pw-gen>Generate</button>
                                    </div>
                                    <div class="pw-meter" aria-hidden="true"><span></span><span></span><span></span></div>
                                    <button class="btn small mt" type="submit">Update mailbox</button>
                                </form>
                            </details>
                            @endcan
                            <details class="acp-exp">
                                <summary>@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Connect Devices</summary>
                                <div class="acp-exp-body">
                                    <dl class="kv">
                                        <dt>Username</dt><dd class="mono">{{ $box->address() }} <button class="btn small ghost" type="button" data-copy="{{ $box->address() }}">copy</button></dd>
                                        <dt>Incoming (IMAP)</dt><dd class="mono">{{ $mailHost }} : 993 (SSL) <button class="btn small ghost" type="button" data-copy="{{ $mailHost }}">copy</button></dd>
                                        <dt>Incoming (POP3)</dt><dd class="mono">{{ $mailHost }} : 995 (SSL)</dd>
                                        <dt>Outgoing (SMTP)</dt><dd class="mono">{{ $mailHost }} : 465 (SSL) — auth required</dd>
                                        <dt>Webmail</dt><dd><a href="{{ route('webmail.index') }}">Open webmail →</a></dd>
                                    </dl>
                                    <p class="help">IMAP/SMTP dono me poora address hi username hai. Password = mailbox password.</p>
                                </div>
                            </details>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No email accounts yet — neeche se pehla mailbox banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ============================== create (cPanel Create form) --}}
@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'mail', 'cls' => 'hico']) Create an Email Account</h3>
    <form method="post" action="{{ route('email.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="localpart">Username</label>
                <div class="row">
                    <input id="localpart" name="localpart" required maxlength="32" placeholder="bob" value="{{ old('localpart') }}" style="flex:1; min-width:140px">
                    <span class="muted">@</span>
                    <select id="domain" name="domain" required style="width:auto; min-width:160px">
                        @forelse ($domains as $d)
                            <option value="{{ $d }}" {{ old('domain') === $d ? 'selected' : '' }}>{{ $d }}</option>
                        @empty
                            <option value="" disabled>No domain on this account</option>
                        @endforelse
                    </select>
                </div>

                <label for="password">Password</label>
                <div class="row" data-pw>
                    <input id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" style="flex:1; min-width:180px">
                    <button class="btn small secondary" type="button" data-pw-show>Show</button>
                    <button class="btn small secondary" type="button" data-pw-gen>Generate</button>
                </div>
                <div class="pw-meter" aria-hidden="true"><span></span><span></span><span></span></div>
                <p class="help">8–72 chars. Generate = strong random password (copy kar ke rakho).</p>
            </div>
            <div>
                <label>Storage Space</label>
                <div class="row">
                    <label class="check" style="margin:0"><input type="radio" name="quota_mode" value="limited" checked data-quota-radio> Space (MB)</label>
                    <input type="number" name="quota_mb" min="1" max="102400" value="{{ old('quota_mb', 1024) }}" style="width:130px">
                    <label class="check" style="margin:0"><input type="radio" name="quota_mode" value="unlimited" data-quota-radio> Unlimited</label>
                </div>
                <p class="help">MAXPOP limit: {{ $maxPop }} mailboxes. Maildir <span class="mono">~/mail/domain/local</span> · bcrypt (plaintext agent tak kabhi nahi jata).</p>
                <button class="btn mt" type="submit">+ Create</button>
            </div>
        </div>
    </form>
</div>
@endcan

{{-- ============================== default/system account (cPanel bottom card) --}}
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'user', 'cls' => 'hico']) Default (system) account</h3>
    <p class="help">System user <span class="mono">{{ $account->username }}</span> ka default address un-routed mail receive karta hai —
        <a href="{{ route('default-address.index') }}">Default Address tool</a> me manage hota hai.
        Forwarding ke liye <a href="{{ route('forwarders.index') }}">Forwarders</a> dekho.</p>
</div>
@endif
@endsection
