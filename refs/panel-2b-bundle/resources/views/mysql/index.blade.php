@extends('layouts.panel')

@section('title', 'MySQL Databases')
@section('subtitle', 'Real MariaDB databases — agent db.create/db.drop (socket auth, SQL stdin)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers create databases here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>MySQL Databases — {{ $account->username }}</h3>
    <p class="help">Agent <span class="mono">db.create</span> se asli MariaDB database banta hai (utf8mb4) aur <span class="mono">db.drop</span> se hat’ta hai — privileges bhi revoke hote hain. Users/grants <a href="{{ route('mysql-users.index') }}">MySQL Users</a> page par. MAXSQL {{ $maxSql }}.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Database</th>
                <th></th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $account->username }}_{{ $row->name }}</td>
                    <td class="right">
                        @can('databases.manage')
                            <form method="post" action="{{ route('mysql.destroy', $row) }}" onsubmit="return confirm('Remove this database?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No databases yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('databases.manage')
<div class="card mt">
    <h3>Create Database</h3>
    <form method="post" action="{{ route('mysql.store') }}" class="stack">
        @csrf
        <label>
            Name
            <span class="mono">{{ $account->username }}_</span>
            <input name="name" value="{{ old('name') }}" maxlength="16" required placeholder="shop">
        </label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Create Database</button>
    </form>
</div>
@endcan
@endif
@endsection
