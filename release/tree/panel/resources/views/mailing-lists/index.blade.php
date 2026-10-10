@extends('layouts.panel')

@section('title', 'Mailing Lists')
@section('subtitle', 'Group addresses — ek mail, saare members ko')

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
        <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Lists</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">/ {{ $maxLst }} allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'list', 'cls' => 'hico']) Limits</h3>
        <p class="help" style="margin:6px 0 0">Har list me max <strong>{{ $maxMembers }}</strong> members.
            List address par aayi mail har member ko forward hoti hai; sirf owner hi members edit kar sakta hai.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Current Lists — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search lists…" data-filter-rows="#acp-mlists tbody tr" aria-label="Search lists">
    </div>
    <div class="table-wrap">
        <table id="acp-mlists">
            <thead><tr><th>List Address</th><th>Owner</th><th>Members</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->address() }}</td>
                    <td class="mono">{{ $row->owner }}</td>
                    <td><span class="badge blue">{{ count($row->members) }} / {{ $maxMembers }}</span></td>
                    <td class="right">
                        @can('email.manage')
                            <div class="row" style="justify-content:flex-end; gap:6px">
                                <details style="display:inline-block; text-align:left">
                                    <summary class="btn small secondary" style="list-style:none; cursor:pointer">Manage</summary>
                                    <form method="post" action="{{ route('mailing-lists.update', $row) }}" class="mt">
                                        @csrf
                                        @method('PATCH')
                                        <label for="owner-{{ $row->id }}">Owner email</label>
                                        <input id="owner-{{ $row->id }}" name="owner" type="email" required maxlength="190" value="{{ $row->owner }}">
                                        <label for="members-{{ $row->id }}">Members (one per line)</label>
                                        <textarea id="members-{{ $row->id }}" name="members" rows="5" maxlength="16000">{{ implode("\n", $row->members) }}</textarea>
                                        <button class="btn small mt" type="submit">Save List</button>
                                    </form>
                                </details>
                                <form method="post" action="{{ route('mailing-lists.destroy', $row) }}" onsubmit="return confirm('Delete list {{ $row->address() }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn small danger" type="submit">Delete</button>
                                </form>
                            </div>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No mailing lists yet — neeche se banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Create a Mailing List</h3>
    <form method="post" action="{{ route('mailing-lists.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="localpart">List address</label>
                <div class="row">
                    <input id="localpart" name="localpart" required maxlength="32" placeholder="team" value="{{ old('localpart') }}" style="flex:1; min-width:120px">
                    <span class="muted">@</span>
                    <select id="domain" name="domain" required style="width:auto; min-width:150px">
                        @forelse ($domains as $d)
                            <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
                        @empty
                            <option value="" disabled>No domain</option>
                        @endforelse
                    </select>
                </div>
                <label for="owner">Owner email</label>
                <input id="owner" name="owner" type="email" required maxlength="190" placeholder="admin@example.com" value="{{ old('owner') }}">
            </div>
            <div>
                <label for="members">Members (one email per line, max {{ $maxMembers }})</label>
                <textarea id="members" name="members" rows="6" maxlength="16000" placeholder="alice@example.com&#10;bob@example.net">{{ old('members') }}</textarea>
                <button class="btn mt" type="submit">+ Create List</button>
            </div>
        </div>
    </form>
</div>
@endcan
@endif
@endsection
