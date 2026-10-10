@extends('layouts.panel')

@section('title', 'Default Address')
@section('subtitle', 'Catch-all — unmatched mail ka destination per domain')

@section('actions')
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
    <a class="btn small secondary" href="{{ route('forwarders.index') }}">Forwarders</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'inbox', 'cls' => 'hico']) Default addresses</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">/ {{ count($domains) }} domains set</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'filter', 'cls' => 'hico']) Kya hota hai</h3>
        <p class="help" style="margin:6px 0 0">Jo mail kisi mailbox/forwarder se match nahi hoti, wo yahan set kiye
            address par jati hai. Ek domain = ek destination. Specific forwards <a href="{{ route('forwarders.index') }}">Forwarders</a> me.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'inbox', 'cls' => 'hico']) Current Default Addresses — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-defaddr tbody tr" aria-label="Search default addresses">
    </div>
    <div class="table-wrap">
        <table id="acp-defaddr">
            <thead><tr><th>Unmatched mail for</th><th>Goes to</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->source() }}</td>
                    <td class="mono">→ {{ $row->dest }}</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('default-address.destroy', $row) }}" onsubmit="return confirm('Remove default address for {{ $row->source() }}?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Koi default address set nahi — unmatched mail bounce hoti hai.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'inbox', 'cls' => 'hico']) Set Default Address</h3>
    <form method="post" action="{{ route('default-address.store') }}">
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
            <div style="flex:1; min-width:220px">
                <label for="dest">Forward unmatched mail to</label>
                <input id="dest" name="dest" type="email" required maxlength="190" placeholder="alice@example.net" value="{{ old('dest') }}">
            </div>
            <button class="btn" type="submit">Save</button>
        </div>
        <p class="help">Destination sirf email address — pipe/shell fail-closed.</p>
    </form>
</div>
@endcan
@endif
@endsection
