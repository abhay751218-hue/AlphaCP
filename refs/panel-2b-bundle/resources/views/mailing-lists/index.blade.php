@extends('layouts.panel')

@section('title', 'Mailing Lists')
@section('subtitle', 'List address + owner — no mailman, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers create mailing lists here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Mailing Lists — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/lists.json</span>. Mailman daemon later. Pipe/shell fail closed. MAXLST {{ $maxLst }}.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>List</th>
                <th>Owner</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->address() }}</td>
                    <td class="mono">{{ $row->owner }}</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('mailing-lists.destroy', $row) }}" onsubmit="return confirm('Remove this list?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No mailing lists yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Nayi list</h3>
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
        <label for="owner">Owner email</label>
        <input id="owner" name="owner" required maxlength="190" placeholder="alice@example.net" value="{{ old('owner') }}">
        <button class="btn mt" type="submit">Add list</button>
    </form>
</div>
@endcan
@endif
@endsection
