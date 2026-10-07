@extends('layouts.panel')

@section('title', 'MySQL Users')
@section('subtitle', 'Real MariaDB users + privileges (agent db.user.*) — password sirf ek baar dikhta hai')

@section('actions')
    <a class="btn small secondary" href="{{ route('mysql.index') }}">Databases</a>
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer workspace</strong>. Customers manage database users here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
@if (session('mysql_user_password'))
<div class="card">
    <h3>Password — sirf abhi dikhega</h3>
    <p class="help">Copy kar lo. Panel ise store nahi karta (Account Panel jaisa).</p>
    <p class="mono mt">{{ session('mysql_user_full') }}</p>
    <p class="mono">{{ session('mysql_user_password') }}</p>
</div>
@endif

<div class="card">
    <h3>MySQL Users — {{ $account->username }}</h3>
    <p class="help">
        Agent <span class="mono">db.user.create</span> / <span class="mono">db.user.grant</span> se asli MariaDB user
        banta hai (socket auth, SQL stdin se — password argv me kabhi nahi). MaxSQL {{ $maxSql }}.
    </p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>User</th>
                <th>Host</th>
                <th>Databases (privileges)</th>
                <th class="right">Actions</th>
            </tr>
            @forelse ($users as $user)
                <tr>
                    <td class="mono">{{ $account->username }}_{{ $user->name }}</td>
                    <td class="mono">{{ $user->host }}</td>
                    <td class="mono">
                        @forelse ($user->databases as $database)
                            {{ $database->fullName($account->username) }}@if (! $loop->last), @endif
                        @empty
                            <span class="empty">no privileges yet</span>
                        @endforelse
                    </td>
                    <td class="right">
                        @can('databases.manage')
                            <form method="post" action="{{ route('mysql-users.password', $user) }}">
                                @csrf
                                <button class="btn small secondary" type="submit">new password</button>
                            </form>
                            <form method="post" action="{{ route('mysql-users.destroy', $user) }}" onsubmit="return confirm('Remove this database user?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No database users yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('databases.manage')
<div class="card mt">
    <h3>Create MySQL User</h3>
    <form method="post" action="{{ route('mysql-users.store') }}" class="stack">
        @csrf
        <label>
            User name
            <span class="mono">{{ $account->username }}_</span>
            <input name="user" value="{{ old('user') }}" maxlength="16" required placeholder="wp_user">
        </label>
        <label>
            Host
            <select name="host">
                <option value="localhost" @selected(old('host', 'localhost') === 'localhost')>localhost (recommended)</option>
                <option value="%" @selected(old('host') === '%')>% (any host — khol deta hai)</option>
            </select>
        </label>
        <fieldset>
            <legend>Databases (ALL PRIVILEGES)</legend>
            @forelse ($databases as $database)
                <label class="row">
                    <input type="checkbox" name="databases[]" value="{{ $database->id }}">
                    <span class="mono">{{ $database->fullName($account->username) }}</span>
                </label>
            @empty
                <p class="empty">Pehle koi database banao (MySQL Databases page).</p>
            @endforelse
        </fieldset>
        @error('user')<p class="error">{{ $message }}</p>@enderror
        @error('host')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Create User</button>
    </form>
</div>

<div class="card mt">
    <h3>Add User To Database</h3>
    @if ($users->isEmpty() || $databases->isEmpty())
        <p class="empty">User aur database dono chahiye.</p>
    @else
        <form method="post" action="{{ route('mysql-users.grant') }}" class="stack">
            @csrf
            <label>
                User
                <select name="mysql_user_id" required>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $account->username }}_{{ $user->name }}@{{ $user->host }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                Database
                <select name="mysql_database_id" required>
                    @foreach ($databases as $database)
                        <option value="{{ $database->id }}">{{ $database->fullName($account->username) }}</option>
                    @endforeach
                </select>
            </label>
            @error('database')<p class="error">{{ $message }}</p>@enderror
            <button class="btn" type="submit">Grant ALL PRIVILEGES</button>
        </form>
    @endif
</div>
@endcan
@endif
@endsection
