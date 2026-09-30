@extends('layouts.panel')

@section('title', 'Forwarders')
@section('subtitle', 'Address → address — no pipe, no shell')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apne forwarders yahin banayega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>Forwarders — {{ $account->username }}</h3>
    <p class="help">Aliases <span class="mono">~/etc/mail/aliases</span>. Dest sirf email. Pipe/shell fail closed. MAXFWD {{ $maxFwd }}.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>From</th>
                <th>To</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->source() }}</td>
                    <td class="mono">{{ $row->dest }}</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('forwarders.destroy', $row) }}" onsubmit="return confirm('Forwarder hataayein?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Koi forwarder nahi.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Naya forwarder</h3>
    <form method="post" action="{{ route('forwarders.store') }}">
        @csrf
        <label for="localpart">Local part</label>
        <input id="localpart" name="localpart" required maxlength="32" placeholder="bob" value="{{ old('localpart') }}">
        <label for="domain">Domain</label>
        <select id="domain" name="domain" required>
            @forelse ($domains as $d)
                <option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>
            @empty
                <option value="" disabled>Koi domain nahi</option>
            @endforelse
        </select>
        <label for="dest">Forward to (email)</label>
        <input id="dest" name="dest" type="email" required maxlength="190" placeholder="alice@example.net" value="{{ old('dest') }}">
        <button class="btn mt" type="submit">Add forwarder</button>
    </form>
</div>
@endcan
@endif
@endsection
