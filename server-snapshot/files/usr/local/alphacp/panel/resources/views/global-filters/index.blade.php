@extends('layouts.panel')

@section('title', 'Global Email Filters')
@section('subtitle', 'Account-wide contains-match — discard/folder, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apne global filters yahin banayega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>Global Email Filters — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/global-filters.json</span>. Saari mailboxes par. Pipe/regex/shell fail closed. Max 50.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>If</th>
                <th>Contains</th>
                <th>Then</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td>{{ $row->field }}</td>
                    <td class="mono">{{ $row->needle }}</td>
                    <td>{{ $row->action }}@if ($row->folder) /{{ $row->folder }}@endif</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('global-filters.destroy', $row) }}" onsubmit="return confirm('Filter hataayein?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Koi global filter nahi.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Naya global filter</h3>
    <form method="post" action="{{ route('global-filters.store') }}">
        @csrf
        <label for="domain">Domain</label>
        <select id="domain" name="domain" required>
            @forelse ($domains as $d)
                <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
            @empty
                <option value="" disabled>Koi domain nahi</option>
            @endforelse
        </select>
        <label for="field">Field</label>
        <select id="field" name="field" required>
            <option value="subject" @selected(old('field', 'subject') === 'subject')>subject</option>
            <option value="from" @selected(old('field') === 'from')>from</option>
            <option value="to" @selected(old('field') === 'to')>to</option>
        </select>
        <label for="needle">Contains</label>
        <input id="needle" name="needle" required maxlength="100" placeholder="viagra" value="{{ old('needle') }}">
        <label for="action">Action</label>
        <select id="action" name="action" required>
            <option value="discard" @selected(old('action', 'discard') === 'discard')>discard</option>
            <option value="folder" @selected(old('action') === 'folder')>deliver to folder</option>
        </select>
        <label for="folder">Folder (action=folder)</label>
        <input id="folder" name="folder" maxlength="32" placeholder="junk" value="{{ old('folder') }}">
        <button class="btn mt" type="submit">Add filter</button>
    </form>
</div>
@endcan
@endif
@endsection
