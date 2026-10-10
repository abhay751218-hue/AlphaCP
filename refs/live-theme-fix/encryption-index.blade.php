@extends('layouts.panel')

@section('title', 'Encryption')
@section('subtitle', 'GnuPG-style keys for signed and encrypted mail')

@section('actions')
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
    <a class="btn small secondary" href="{{ route('ssl.index') }}">SSL/TLS</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Keys</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">keys registered</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'lock', 'cls' => 'hico']) Kyun use karo</h3>
        <p class="help" style="margin:6px 0 0">Har address ke liye key banao — recipients public key se encrypt karke
            bhej sakte hain, aur tumhari signed mail verify kar sakte hain.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Current Keys — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search keys…" data-filter-rows="#acp-enckeys tbody tr" aria-label="Search keys">
    </div>
    <div class="table-wrap">
        <table id="acp-enckeys">
            <thead><tr><th>Address</th><th>Comment</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono"><span class="badge green">key</span> {{ $row->address() }}</td>
                    <td>{{ $row->comment ?: '—' }}</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('encryption.destroy', $row) }}" onsubmit="return confirm('Delete key for {{ $row->address() }}?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No keys yet — neeche se banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Create a New Key</h3>
    <form method="post" action="{{ route('encryption.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="localpart">Address</label>
                <div class="row">
                    <input id="localpart" name="localpart" required maxlength="32" placeholder="bob" value="{{ old('localpart') }}" style="flex:1; min-width:120px">
                    <span class="muted">@</span>
                    <select id="domain" name="domain" required style="width:auto; min-width:150px">
                        @forelse ($domains as $d)
                            <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
                        @empty
                            <option value="" disabled>No domain</option>
                        @endforelse
                    </select>
                </div>
            </div>
            <div style="flex:1; min-width:200px">
                <label for="comment">Comment <span class="muted">(optional)</span></label>
                <input id="comment" name="comment" maxlength="100" placeholder="Work key" value="{{ old('comment') }}">
            </div>
            <button class="btn" type="submit">+ Generate Key</button>
        </div>
    </form>
</div>
@endcan
@endif
@endsection
