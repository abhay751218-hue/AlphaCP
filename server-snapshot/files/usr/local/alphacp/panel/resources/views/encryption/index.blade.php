@extends('layouts.panel')

@section('title', 'Encryption')
@section('subtitle', 'GnuPG identity rows — no gpg binary, no private key, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers create encryption keys here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Encryption — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/mail/encrypt.json</span>. GnuPG daemon later. No private key. Pipe/shell fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Address</th>
                <th>Comment</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->address() }}</td>
                    <td>{{ $row->comment }}</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('encryption.destroy', $row) }}" onsubmit="return confirm('Remove this key?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No encryption keys yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Nayi key identity</h3>
    <form method="post" action="{{ route('encryption.store') }}">
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
        <label for="comment">Comment</label>
        <input id="comment" name="comment" required maxlength="100" placeholder="bob key" value="{{ old('comment') }}">
        <button class="btn mt" type="submit">Add key</button>
    </form>
</div>
@endcan
@endif
@endsection
