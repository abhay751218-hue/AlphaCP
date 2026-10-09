@extends('layouts.panel')

@section('title', 'BoxTrapper')
@section('subtitle', 'Challenge–response spam protection with allow list')

@section('actions')
    <a class="btn small secondary" href="{{ route('spam-filters.index') }}">Spam Filters</a>
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'inbox', 'cls' => 'hico']) Status</h3>
        <p class="mt">
            @if ($enabled)
                <span class="badge green">Enabled</span> — unknown senders ko verification challenge jata hai.
            @else
                <span class="badge gray">Disabled</span> — saari mail seedha deliver hoti hai.
            @endif
        </p>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico']) Allow list</h3>
        <div class="stat"><span class="num">{{ count($allowlist) }}</span><span class="unit">trusted senders</span></div>
        <p class="help">Allow-listed senders ko kabhi challenge nahi jata.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico']) Allow List — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-boxtrap tbody tr" aria-label="Search allow list">
    </div>
    <div class="table-wrap">
        <table id="acp-boxtrap">
            <thead><tr><th>Trusted sender</th></tr></thead>
            <tbody>
            @forelse ($allowlist as $e)
                <tr><td class="mono"><span class="badge green">allow</span> {{ $e }}</td></tr>
            @empty
                <tr><td class="empty">Allow list khali hai.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Update BoxTrapper</h3>
    <form method="post" action="{{ route('boxtrapper.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="enabled">BoxTrapper</label>
                <select id="enabled" name="enabled" style="min-width:140px">
                    <option value="1" @selected(old('enabled', $enabled ? '1' : '0') === '1')>Enabled</option>
                    <option value="0" @selected(old('enabled', $enabled ? '1' : '0') === '0')>Disabled</option>
                </select>
            </div>
            <div style="flex:1; min-width:200px">
                <label for="dest">Add trusted sender <span class="muted">(optional)</span></label>
                <input id="dest" name="dest" type="email" maxlength="190" placeholder="friend@example.net" value="{{ old('dest') }}">
            </div>
            <button class="btn" type="submit">Save</button>
        </div>
        <p class="help">Enable karne par unknown senders ko ek verification reply bhejna hota hai, tabhi mail inbox me aati hai.</p>
    </form>
</div>
@endcan
@endif
@endsection
