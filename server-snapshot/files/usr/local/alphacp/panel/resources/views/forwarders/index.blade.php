@extends('layouts.panel')

@section('title', 'Forwarders')
@section('subtitle', 'Send a copy of incoming mail to another address')

@section('actions')
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
    <a class="btn small secondary" href="{{ route('default-address.index') }}">Default Address</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers create forwarders here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else

{{-- stats strip --}}
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Email forwarders</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">/ {{ $maxFwd }} allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'inbox', 'cls' => 'hico']) Catch-all (default address)</h3>
        <p class="help" style="margin:6px 0 0">Un-routed mail ke liye domain-level forward
            <a href="{{ route('default-address.index') }}">Default Address tool</a> me set hota hai.</p>
    </div>
</div>

{{-- list --}}
<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Forwarders — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search forwarders…"
               data-filter-rows="#acp-forwarders tbody tr" aria-label="Search forwarders">
    </div>
    <div class="table-wrap">
        <table id="acp-forwarders">
            <thead>
            <tr>
                <th>Address</th>
                <th>Forward to</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->source() }}</td>
                    <td class="mono">→ {{ $row->dest }}</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('forwarders.destroy', $row) }}" onsubmit="return confirm('Remove forwarder {{ $row->source() }}?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No forwarders yet — neeche se pehla forwarder banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Aliases <span class="mono">~/etc/mail/aliases</span> me store hote hain. Destination sirf email address —
        pipe/shell fail-closed. Mailbox exist karti ho to copy bhi deliver hoti hai.</p>
</div>

{{-- create --}}
@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Add Forwarder</h3>
    <form method="post" action="{{ route('forwarders.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="localpart">Address to forward</label>
                <div class="row">
                    <input id="localpart" name="localpart" required maxlength="32" placeholder="bob" value="{{ old('localpart') }}" style="flex:1; min-width:140px">
                    <span class="muted">@</span>
                    <select id="domain" name="domain" required style="width:auto; min-width:160px">
                        @forelse ($domains as $d)
                            <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
                        @empty
                            <option value="" disabled>No domain</option>
                        @endforelse
                    </select>
                </div>
            </div>
            <div>
                <label for="dest">Forward to email address</label>
                <input id="dest" name="dest" type="email" required maxlength="190" placeholder="alice@example.net" value="{{ old('dest') }}">
                <button class="btn mt" type="submit">+ Add Forwarder</button>
            </div>
        </div>
    </form>
</div>
@endcan
@endif
@endsection
