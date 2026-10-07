@extends('layouts.panel')

@section('title', 'Spam Filters')
@section('subtitle', 'Score + blacklist/whitelist — no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers set spam filters here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Spam Filters — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/spam.json</span>. SpamAssassin daemon later. Pipe/shell fail closed. Score 1–10.</p>
    <p>Required score: <strong>{{ $score }}</strong></p>
    <div class="table-wrap mt">
        <table>
            <tr><th>Blacklist</th></tr>
            @forelse ($blacklist as $addr)
                <tr><td class="mono">{{ $addr }}</td></tr>
            @empty
                <tr><td class="empty">Khaali</td></tr>
            @endforelse
        </table>
    </div>
    <div class="table-wrap mt">
        <table>
            <tr><th>Whitelist</th></tr>
            @forelse ($whitelist as $addr)
                <tr><td class="mono">{{ $addr }}</td></tr>
            @empty
                <tr><td class="empty">Khaali</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Update</h3>
    <form method="post" action="{{ route('spam-filters.store') }}">
        @csrf
        <label for="required_score">Required score</label>
        <input id="required_score" name="required_score" type="number" min="1" max="10" value="{{ old('required_score', $score) }}" required>
        <label for="list">Add to</label>
        <select id="list" name="list">
            <option value="black" @selected(old('list', 'black') === 'black')>blacklist</option>
            <option value="white" @selected(old('list') === 'white')>whitelist</option>
        </select>
        <label for="dest">Email (optional)</label>
        <input id="dest" name="dest" maxlength="190" placeholder="spam@example.net" value="{{ old('dest') }}">
        <button class="btn mt" type="submit">Save spam filters</button>
    </form>
</div>
@endcan
@endif
@endsection
