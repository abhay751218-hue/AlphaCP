@extends('layouts.panel')

@section('title', 'Mailing Lists')
@section('subtitle', 'Exim distribution lists — subscribers receive a copy')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers create and manage their distribution lists here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Mailing Lists — {{ $account->username }}</h3>
    <p class="help">Each message to a list is expanded by Exim and delivered to its static subscriber addresses.
        The owner is the administrative contact; add the owner as a subscriber if they should receive list posts.
        Package MAXLST {{ $maxLst }}; maximum {{ $maxMembers }} subscribers per list.
        This distribution-list service does not include Mailman moderation queues or public archives.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>List</th>
                <th>Owner</th>
                <th>Subscribers</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->address() }}</td>
                    <td class="mono">{{ $row->owner }}</td>
                    <td>{{ count(is_array($row->members) && $row->members !== [] ? $row->members : [$row->owner]) }}</td>
                    <td class="right">
                        @can('email.manage')
                            <details>
                                <summary class="btn small secondary">Manage subscribers</summary>
                                <form method="post" action="{{ route('mailing-lists.update', $row) }}" class="mt">
                                    @csrf
                                    @method('PATCH')
                                    <label for="owner-{{ $row->id }}">Owner email</label>
                                    <input id="owner-{{ $row->id }}" name="owner" type="email" required maxlength="190" value="{{ old('owner', $row->owner) }}">
                                    <label for="members-{{ $row->id }}">Subscriber addresses (one per line, comma, or semicolon)</label>
                                    <textarea id="members-{{ $row->id }}" name="members" rows="5" maxlength="50000" required>{{ old('members', implode("\n", is_array($row->members) && $row->members !== [] ? $row->members : [$row->owner])) }}</textarea>
                                    <button class="btn small mt" type="submit">Save subscribers</button>
                                </form>
                            </details>
                            <form method="post" action="{{ route('mailing-lists.destroy', $row) }}" class="mt" onsubmit="return confirm('Remove this list?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No mailing lists yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Nayi distribution list</h3>
    <form method="post" action="{{ route('mailing-lists.store') }}">
        @csrf
        <label for="localpart">List name</label>
        <input id="localpart" name="localpart" required maxlength="32" placeholder="news" value="{{ old('localpart') }}">
        <label for="domain">Domain</label>
        <select id="domain" name="domain" required>
            @forelse ($domains as $d)
                <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
            @empty
                <option value="" disabled>No domain</option>
            @endforelse
        </select>
        <label for="owner">Owner email (admin contact)</label>
        <input id="owner" name="owner" type="email" required maxlength="190" placeholder="alice@example.net" value="{{ old('owner') }}">
        <label for="members">Subscriber addresses (one per line, comma, or semicolon)</label>
        <textarea id="members" name="members" rows="6" maxlength="50000" required placeholder="alice@example.net&#10;team@example.org">{{ old('members') }}</textarea>
        <p class="help">Add 1–{{ $maxMembers }} valid email addresses. Shell commands and pipes are rejected.</p>
        <button class="btn mt" type="submit">Add distribution list</button>
    </form>
</div>
@endcan
@endif
@endsection
