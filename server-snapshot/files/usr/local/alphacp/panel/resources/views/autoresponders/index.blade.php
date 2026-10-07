@extends('layouts.panel')

@section('title', 'Autoresponders')
@section('subtitle', 'Vacation auto-reply — no pipe, no shell')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers create autoresponders here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Autoresponders — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/autorespond</span>. Subject/body me pipe/shell fail closed. MAXRESP {{ $maxResp }}.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>From</th>
                <th>Subject</th>
                <th>Interval</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->source() }}</td>
                    <td>{{ $row->subject }}</td>
                    <td>{{ $row->interval_h }}h</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('autoresponders.destroy', $row) }}" onsubmit="return confirm('Remove this autoresponder?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No autoresponders yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>New autoresponder</h3>
    <form method="post" action="{{ route('autoresponders.store') }}">
        @csrf
        <label for="localpart">Local part</label>
        <input id="localpart" name="localpart" required maxlength="32" placeholder="bob" value="{{ old('localpart') }}">
        <label for="domain">Domain</label>
        <select id="domain" name="domain" required>
            @forelse ($domains as $d)
                <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
            @empty
                <option value="" disabled>No domain</option>
            @endforelse
        </select>
        <label for="subject">Subject</label>
        <input id="subject" name="subject" required maxlength="200" placeholder="Out of office" value="{{ old('subject') }}">
        <label for="interval_h">Interval (hours, 0 = har mail)</label>
        <input id="interval_h" name="interval_h" type="number" min="0" max="168" value="{{ old('interval_h', 24) }}">
        <label for="body">Body</label>
        <textarea id="body" name="body" required maxlength="4000" rows="6" placeholder="I am away until Monday.">{{ old('body') }}</textarea>
        <button class="btn mt" type="submit">Add autoresponder</button>
    </form>
</div>
@endcan
@endif
@endsection
