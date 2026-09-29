@extends('layouts.panel')

@section('title', 'Directory Privacy')
@section('subtitle', 'Password-protect a folder — Apache Basic Auth (bcrypt)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apne folders yahin se lock karega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>Directory Privacy — {{ $account->username }}</h3>
    <p class="help"><span class="mono">AuthUserFile</span> <span class="mono">~/etc/privacy/</span> me. Path home ke andar, <span class="mono">..</span> nahi.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Folder</th>
                <th>Realm</th>
                <th>Users</th>
                <th></th>
            </tr>
            @forelse ($entries as $row)
                <tr>
                    <td class="mono">~/{{ $row['path'] }}</td>
                    <td>{{ $row['realm'] }}</td>
                    <td class="mono">{{ implode(', ', array_column($row['users'], 'name')) }}</td>
                    <td class="right">
                        @can('privacy.manage')
                            <form method="post" action="{{ route('privacy.destroy') }}" onsubmit="return confirm('Protection hataayein?')">
                                @csrf
                                <input type="hidden" name="path" value="{{ $row['path'] }}">
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Koi protected folder nahi.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('privacy.manage')
<div class="card mt">
    <h3>Folder protect karo</h3>
    <p class="help">Example path: <span class="mono">public_html/secret</span>. Password panel bcrypt hash karke agent ko bhejta hai — plaintext nahi.</p>
    <form method="post" action="{{ route('privacy.store') }}">
        @csrf
        <label for="path">Folder (relative)</label>
        <input id="path" name="path" required maxlength="240" placeholder="public_html/secret" value="{{ old('path') }}">
        <label for="realm">Protected area name</label>
        <input id="realm" name="realm" maxlength="64" placeholder="Private" value="{{ old('realm', 'Protected') }}">
        <label for="name">Username</label>
        <input id="name" name="name" required maxlength="32" placeholder="bob" value="{{ old('name') }}">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required minlength="5" maxlength="72">
        <button class="btn mt" type="submit">Protect</button>
    </form>
</div>
@endcan
@endif
@endsection
