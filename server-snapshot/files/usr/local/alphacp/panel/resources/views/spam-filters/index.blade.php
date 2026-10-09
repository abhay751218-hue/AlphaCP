@extends('layouts.panel')

@section('title', 'Spam Filters')
@section('subtitle', 'SpamAssassin-style scoring + black/white lists')

@section('actions')
    <a class="btn small secondary" href="{{ route('email-filters.index') }}">Email Filters</a>
    <a class="btn small secondary" href="{{ route('boxtrapper.index') }}">BoxTrapper</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Spam Threshold Score</h3>
        <div class="stat"><span class="num">{{ $score }}</span><span class="unit">/ 10 (kam = strict)</span></div>
        <p class="help">Jis mail ka spam-score threshold se upar ho, wo spam treat hoti hai. 5 default; 1 bahut strict, 10 bahut loose.</p>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'list', 'cls' => 'hico']) Lists</h3>
        <div class="row" style="gap:16px; flex-wrap:wrap">
            <div class="stat"><span class="num">{{ count($blacklist) }}</span><span class="unit">blacklisted</span></div>
            <div class="stat"><span class="num">{{ count($whitelist) }}</span><span class="unit">whitelisted</span></div>
        </div>
        <p class="help">Blacklist = hamesha spam · Whitelist = kabhi spam nahi.</p>
    </div>
</div>

<div class="grid cols-2 mt">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'ban', 'cls' => 'hico']) Blacklist</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sender / pattern</th></tr></thead>
                <tbody>
                @forelse ($blacklist as $e)
                    <tr><td class="mono"><span class="badge red">block</span> {{ $e }}</td></tr>
                @empty
                    <tr><td class="empty">Blacklist khali hai.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico']) Whitelist</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sender / pattern</th></tr></thead>
                <tbody>
                @forelse ($whitelist as $e)
                    <tr><td class="mono"><span class="badge green">allow</span> {{ $e }}</td></tr>
                @empty
                    <tr><td class="empty">Whitelist khali hai.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Update Spam Settings</h3>
    <form method="post" action="{{ route('spam-filters.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="required_score">Threshold score (1–10)</label>
                <input id="required_score" name="required_score" type="number" min="1" max="10" required value="{{ old('required_score', $score) }}" style="max-width:120px">
            </div>
            <div>
                <label for="list">Add to list</label>
                <select id="list" name="list" style="min-width:150px">
                    <option value="black" @selected(old('list') === 'black')>Blacklist (block)</option>
                    <option value="white" @selected(old('list') === 'white')>Whitelist (allow)</option>
                </select>
            </div>
            <div style="flex:1; min-width:200px">
                <label for="dest">Address / pattern <span class="muted">(optional)</span></label>
                <input id="dest" name="dest" maxlength="190" placeholder="spammer@bad.tld" value="{{ old('dest') }}">
            </div>
            <button class="btn" type="submit">Save</button>
        </div>
        <p class="help">Address khali chhodo to sirf threshold score update hota hai.</p>
    </form>
</div>
@endcan
@endif
@endsection
