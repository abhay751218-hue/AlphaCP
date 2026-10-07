@extends('layouts.panel')

@section('title', 'Directory Privacy')
@section('subtitle', 'Password-protect a folder — Apache Basic Auth (bcrypt)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers protect folders here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Directory Privacy — {{ $account->username }}</h3>
    <p class="help"><span class="mono">AuthUserFile</span> under <span class="mono">~/etc/privacy/</span>. Path must stay inside home, no <span class="mono">..</span>.</p>
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
                            <form method="post" action="{{ route('privacy.destroy') }}" onsubmit="return confirm('Remove this protection?')">
                                @csrf
                                <input type="hidden" name="path" value="{{ $row['path'] }}">
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No protected folders yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('privacy.manage')
<div class="card mt">
    <h3>Protect folder</h3>
    <p class="help">Example path: <span class="mono">public_html/secret</span>. The panel bcrypt-hashes the password before sending it to the agent — no plaintext.</p>
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
