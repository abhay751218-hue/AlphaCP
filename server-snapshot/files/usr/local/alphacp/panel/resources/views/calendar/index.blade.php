@extends('layouts.panel')

@section('title', 'Calendars and Contacts')
@section('subtitle', 'CalDAV / CardDAV collections for this account')

@section('actions')
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
    <a class="btn small secondary" href="{{ route('webmail.index') }}">Webmail</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'calendar', 'cls' => 'hico']) Calendars</h3>
        <div class="stat"><span class="num">{{ $calendars->count() }}</span><span class="unit">collections</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Contact books</h3>
        <div class="stat"><span class="num">{{ $contacts->count() }}</span><span class="unit">collections</span></div>
    </div>
</div>

<div class="grid cols-2 mt">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'calendar', 'cls' => 'hico']) Calendars — {{ $account->username }}</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th class="right">Actions</th></tr></thead>
                <tbody>
                @forelse ($calendars as $row)
                    <tr>
                        <td><span class="badge blue">calendar</span> {{ $row->name }}</td>
                        <td class="right">
                            @can('email.manage')
                                <form method="post" action="{{ route('calendar.destroy', $row) }}" onsubmit="return confirm('Delete calendar {{ $row->name }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn small danger" type="submit">Delete</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="empty">No calendars yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Contact Books — {{ $account->username }}</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th class="right">Actions</th></tr></thead>
                <tbody>
                @forelse ($contacts as $row)
                    <tr>
                        <td><span class="badge green">contacts</span> {{ $row->name }}</td>
                        <td class="right">
                            @can('email.manage')
                                <form method="post" action="{{ route('calendar.destroy', $row) }}" onsubmit="return confirm('Delete contact book {{ $row->name }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn small danger" type="submit">Delete</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="empty">No contact books yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'calendar', 'cls' => 'hico']) Create Collection</h3>
    <form method="post" action="{{ route('calendar.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="kind">Type</label>
                <select id="kind" name="kind" required style="min-width:160px">
                    <option value="calendar" @selected(old('kind', 'calendar') === 'calendar')>Calendar</option>
                    <option value="contact" @selected(old('kind') === 'contact')>Contact book</option>
                </select>
            </div>
            <div style="flex:1; min-width:200px">
                <label for="name">Name</label>
                <input id="name" name="name" required maxlength="64" placeholder="Work" value="{{ old('name') }}">
            </div>
            <button class="btn" type="submit">+ Create</button>
        </div>
    </form>
</div>
@endcan
@endif
@endsection
