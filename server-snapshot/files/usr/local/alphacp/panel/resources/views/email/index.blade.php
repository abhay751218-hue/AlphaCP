@extends('layouts.panel')

@section('title', 'Email Accounts')
@section('subtitle', 'Mailboxes + quota — virtual users under account home')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apne mailboxes yahin banayega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>Email Accounts — {{ $account->username }}</h3>
    <p class="help">Maildir <span class="mono">~/mail/domain/local</span> · Dovecot passwd-file bcrypt. MAXPOP {{ $maxPop }}. Plaintext password agent tak nahi jata.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Address</th>
                <th>Quota</th>
                <th></th>
            </tr>
            @forelse ($boxes as $box)
                <tr>
                    <td class="mono">{{ $box->address() }}</td>
                    <td>{{ $box->quota_mb < 0 ? 'unlimited' : $box->quota_mb . ' MB' }}</td>
                    <td class="right">
                        @can('email.manage')
                            <form method="post" action="{{ route('email.destroy', $box) }}" onsubmit="return confirm('Mailbox hataayein?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Koi mailbox nahi.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('email.manage')
<div class="card mt">
    <h3>Naya mailbox</h3>
    <form method="post" action="{{ route('email.store') }}">
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
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required minlength="8" maxlength="72">
        <label for="quota_mb">Quota MB (−1 unlimited)</label>
        <input id="quota_mb" name="quota_mb" type="number" min="-1" max="102400" required value="{{ old('quota_mb', 1024) }}">
        <button class="btn mt" type="submit">Create mailbox</button>
    </form>
</div>
@endcan
@endif
@endsection
