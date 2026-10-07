@extends('layouts.panel')

@section('title', 'Calendar')
@section('subtitle', 'Calendar & Contacts names — no CalDAV/CardDAV daemon, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers manage calendars and contacts here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Calendar — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/calendar.json</span>. CalDAV/CardDAV later. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Calendars</th>
                <th></th>
            </tr>
            @forelse ($calendars as $row)
                <tr>
                    <td class="mono">{{ $row->name }}</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('calendar.destroy', $row) }}" onsubmit="return confirm('Remove this calendar?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No calendars yet.</td></tr>
            @endforelse
        </table>
    </div>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Contacts</th>
                <th></th>
            </tr>
            @forelse ($contacts as $row)
                <tr>
                    <td class="mono">{{ $row->name }}</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('calendar.destroy', $row) }}" onsubmit="return confirm('Remove this contact?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No contacts yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>New calendar / contact</h3>
    <form method="post" action="{{ route('calendar.store') }}">
        @csrf
        <label for="kind">Kind</label>
        <select id="kind" name="kind" required>
            <option value="calendar" @selected(old('kind', 'calendar') === 'calendar')>calendar</option>
            <option value="contact" @selected(old('kind') === 'contact')>contact</option>
        </select>
        <label for="name">Name</label>
        <input id="name" name="name" required maxlength="64" placeholder="Work" value="{{ old('name') }}">
        <button class="btn mt" type="submit">Add</button>
    </form>
</div>
@endcan
@endif
@endsection
