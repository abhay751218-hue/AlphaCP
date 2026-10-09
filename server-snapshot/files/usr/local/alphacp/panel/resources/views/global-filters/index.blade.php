@extends('layouts.panel')

@section('title', 'Global Email Filters')
@section('subtitle', 'Account-wide rules — saari mailboxes par')

@section('actions')
    <a class="btn small secondary" href="{{ route('email-filters.index') }}">Email Filters (per-box)</a>
    <a class="btn small secondary" href="{{ route('spam-filters.index') }}">Spam Filters</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'filter', 'cls' => 'hico']) Global filters</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">/ 50 allowed</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'mail', 'cls' => 'hico']) Scope</h3>
        <p class="help" style="margin:6px 0 0">Ye rules domain ki SAARI mailboxes par lagte hain —
            pehle global filters, phir <a href="{{ route('email-filters.index') }}">per-mailbox filters</a> chalte hain.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'filter', 'cls' => 'hico']) Current Filters — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search filters…" data-filter-rows="#acp-filters tbody tr" aria-label="Search filters">
    </div>
    <div class="table-wrap">
        <table id="acp-filters">
            <thead><tr><th>Domain</th><th>Rule</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td>
                        <span class="badge blue">{{ $row->field }}</span> contains
                        <span class="mono">"{{ $row->needle }}"</span> →
                        <span class="badge {{ $row->action === 'discard' ? 'red' : 'green' }}">{{ $row->action }}@if ($row->folder)/{{ $row->folder }}@endif</span>
                    </td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('global-filters.destroy', $row) }}" onsubmit="return confirm('Remove this filter?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No filters yet — neeche se banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'filter', 'cls' => 'hico']) Create a New Global Filter</h3>
    <form method="post" action="{{ route('global-filters.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="domain">Domain</label>
                <select id="domain" name="domain" required>
                    @forelse ($domains as $d)
                        <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
                    @empty
                        <option value="" disabled>No domain</option>
                    @endforelse
                </select>
            </div>
            <div>
                <label for="field">If (field)</label>
                <select id="field" name="field" required>
                    <option value="subject" @selected(old('field', 'subject') === 'subject')>Subject</option>
                    <option value="from" @selected(old('field') === 'from')>From</option>
                    <option value="to" @selected(old('field') === 'to')>To</option>
                </select>
                <label for="needle">Contains</label>
                <input id="needle" name="needle" required maxlength="100" placeholder="lottery winner" value="{{ old('needle') }}">
            </div>
            <div>
                <label for="action">Then (action)</label>
                <select id="action" name="action" required>
                    <option value="discard" @selected(old('action', 'discard') === 'discard')>Discard (delete silently)</option>
                    <option value="folder" @selected(old('action') === 'folder')>Deliver to folder</option>
                </select>
                <label for="folder">Folder <span class="muted">(sirf "deliver to folder" ke liye)</span></label>
                <input id="folder" name="folder" maxlength="32" placeholder="junk" value="{{ old('folder') }}">
                <button class="btn mt" type="submit">+ Add Filter</button>
            </div>
        </div>
    </form>
</div>
@endcan
@endif
@endsection
