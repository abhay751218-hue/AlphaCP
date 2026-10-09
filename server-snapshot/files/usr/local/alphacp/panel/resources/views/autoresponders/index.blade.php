@extends('layouts.panel')

@section('title', 'Autoresponders')
@section('subtitle', 'Vacation / out-of-office auto-replies')

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
        <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Autoresponders</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">/ {{ $maxResp }} allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'clock', 'cls' => 'hico']) Interval kya hai</h3>
        <p class="help" style="margin:6px 0 0">Same sender ko dobara reply tabhi jayega jab interval (hours) nikal
            jaye — 0 = har mail par reply. Spam-loop se bachne ke liye 24h recommended.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Current Autoresponders — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-auto tbody tr" aria-label="Search autoresponders">
    </div>
    <div class="table-wrap">
        <table id="acp-auto">
            <thead><tr><th>Address</th><th>Subject</th><th>Interval</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->source() }}</td>
                    <td>{{ $row->subject }}</td>
                    <td><span class="badge blue">{{ $row->interval_h }}h</span></td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('autoresponders.destroy', $row) }}" onsubmit="return confirm('Remove autoresponder for {{ $row->source() }}?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No autoresponders yet — neeche se banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Add Autoresponder</h3>
    <form method="post" action="{{ route('autoresponders.store') }}">
        @csrf
        <div class="grid cols-2">
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
                <label for="subject">Subject</label>
                <input id="subject" name="subject" required maxlength="200" placeholder="Out of office" value="{{ old('subject') }}">
                <label for="interval_h">Interval (hours, 0 = har mail)</label>
                <input id="interval_h" name="interval_h" type="number" min="0" max="168" value="{{ old('interval_h', 24) }}" style="max-width:140px">
            </div>
            <div>
                <label for="body">Message body</label>
                <textarea id="body" name="body" required maxlength="4000" rows="8" placeholder="I am away until Monday.">{{ old('body') }}</textarea>
                <button class="btn mt" type="submit">+ Add Autoresponder</button>
            </div>
        </div>
    </form>
</div>
@endcan
@endif
@endsection
