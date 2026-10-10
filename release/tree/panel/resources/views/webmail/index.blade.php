@extends('layouts.panel')

@section('title', 'Webmail')
@section('subtitle', 'Roundcube one-click login — jo mailbox chuno usi se SSO')

@section('actions')
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
    <a class="btn small secondary" href="{{ route('email-disk.index') }}">Email Disk Usage</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <h3>@include('partials.icons', ['icon' => 'mail', 'cls' => 'hico']) Webmail</h3>
    <p class="help" style="margin:6px 0 0">Ye tool <strong>customer account panel</strong> ka hissa hai —
        customers yahan se apne mailbox Roundcube me kholte hain (port {{ $webmailPort }}).</p>
</div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

@if (session('success'))
    <div class="card mb"><p class="help" style="margin:0">✅ {{ session('success') }}</p></div>
@endif
@if ($errors->any())
    <div class="card mb"><p class="empty" style="margin:0">⚠️ {{ $errors->first() }}</p></div>
@endif

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'mail', 'cls' => 'hico']) Webmail — {{ $account->username }}</h3>
        <div class="row mt" style="gap:20px; flex-wrap:wrap">
            <div class="stat"><span class="num">{{ $mailboxes->count() }}</span><span class="unit">mailboxes</span></div>
            <div class="stat"><span class="num">{{ $enabled ? 'ON' : 'OFF' }}</span><span class="unit">webmail access</span></div>
            <div class="stat"><span class="num">{{ $webmailPort }}</span><span class="unit">port (Roundcube)</span></div>
        </div>
        <p class="help" style="margin:10px 0 0">Client: <span class="badge blue">{{ $client }}</span> —
            Open dabate hi 10-minute ka one-time SSO token banta hai, password dobara nahi poochta.</p>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Preference</h3>
        <form method="post" action="{{ route('webmail.store') }}">
            @csrf
            <div class="row" style="flex-wrap:wrap; align-items:flex-end">
                <div>
                    <label for="wm-enabled">Webmail access</label>
                    <select id="wm-enabled" name="enabled" style="min-width:110px">
                        <option value="1" @selected($enabled)>On</option>
                        <option value="0" @selected(! $enabled)>Off</option>
                    </select>
                </div>
                <div>
                    <label for="wm-client">Client</label>
                    <select id="wm-client" name="client" style="min-width:130px">
                        <option value="roundcube" @selected($client === 'roundcube')>Roundcube</option>
                        <option value="horde" @selected($client === 'horde')>Horde</option>
                    </select>
                </div>
                <button class="btn" type="submit">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'inbox', 'cls' => 'hico']) Mailboxes — one-click login</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(260px,100%)" placeholder="Filter mailboxes…" data-filter-rows="#acp-webmail tbody tr" aria-label="Filter mailboxes">
    </div>
    @if (! $enabled)
        <p class="empty">Webmail access OFF hai — upar Preference me On karke Save karo.</p>
    @elseif ($mailboxes->isEmpty())
        <p class="empty">Koi mailbox nahi — pehle <a href="{{ route('email.index') }}">Email Accounts</a> me ek banayein.</p>
    @else
    <div class="table-wrap">
        <table id="acp-webmail">
            <thead><tr><th>Mailbox</th><th>Quota</th><th>Open</th></tr></thead>
            <tbody>
            @foreach ($mailboxes as $mb)
                <tr>
                    <td class="mono">{{ $mb->address() }}</td>
                    <td class="muted">{{ $mb->quota_mb ? $mb->quota_mb . ' MB' : '∞' }}</td>
                    <td>
                        <form method="post" action="{{ route('webmail.open') }}">
                            @csrf
                            <input type="hidden" name="mailbox_id" value="{{ $mb->id }}">
                            <button class="btn small" type="submit">📬 Open Webmail</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endif
@endsection
