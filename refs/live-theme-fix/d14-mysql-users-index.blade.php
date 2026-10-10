@extends('layouts.panel')

@section('title', 'MySQL Users')
@section('subtitle', 'Users, passwords aur database privileges')

@section('actions')
    <a class="btn small secondary" href="{{ route('mysql.index') }}">Databases</a>
    <a class="btn small secondary" href="{{ route('mysql-wizard.index') }}">Database Wizard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers manage database users here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
@if (session('mysql_user_password'))
<div class="card" style="border-color:#f0d9b0; background:#fdf4e3">
    <h3>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Password — sirf ABHI dikhega</h3>
    <p class="help">Copy kar lo — panel ise store nahi karta. Kho gaya to "New password" se naya banta hai.</p>
    <dl class="kv mt">
        <dt>User</dt><dd class="mono">{{ session('mysql_user_full') }} <button class="btn small ghost" type="button" data-copy="{{ session('mysql_user_full') }}">copy</button></dd>
        <dt>Password</dt><dd class="mono">{{ session('mysql_user_password') }} <button class="btn small ghost" type="button" data-copy="{{ session('mysql_user_password') }}">copy</button></dd>
    </dl>
</div>
@endif

{{-- current users --}}
<div class="card {{ session('mysql_user_password') ? 'mt' : '' }}">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Current Users — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search users…"
               data-filter-rows="#acp-dbusers tbody tr" aria-label="Search database users">
    </div>
    <div class="table-wrap">
        <table id="acp-dbusers">
            <thead>
            <tr>
                <th>User</th>
                <th>Host</th>
                <th>Privileges (databases)</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td class="mono">{{ $account->username }}_{{ $user->name }}</td>
                    <td class="mono muted">{{ $user->host }}</td>
                    <td>
                        @forelse ($user->databases as $database)
                            <span class="badge green mono">{{ $database->fullName($account->username) }}</span>
                        @empty
                            <span class="muted">no privileges yet</span>
                        @endforelse
                    </td>
                    <td class="right">
                        @can('databases.manage')
                            <form method="post" action="{{ route('mysql-users.destroy', $user) }}" onsubmit="return confirm('Remove user {{ $account->username }}_{{ $user->name }}@{{ $user->host }}?')" style="display:inline">
                                @csrf
                                @method('DELETE')
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
                @can('databases.manage')
                <tr class="acp-subrow">
                    <td colspan="4">
                        <details class="acp-exp">
                            <summary>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Change password</summary>
                            <form method="post" action="{{ route('mysql-users.password', $user) }}" class="acp-exp-body">
                                @csrf
                                <label for="dbpw-{{ $user->id }}">New password <span class="muted">(blank = strong random; letters+numbers 10–64)</span></label>
                                <div class="row" data-pw>
                                    <input id="dbpw-{{ $user->id }}" name="password" type="password" pattern="[A-Za-z0-9]{10,64}" autocomplete="new-password" style="flex:1; min-width:180px">
                                    <button class="btn small secondary" type="button" data-pw-show>Show</button>
                                    <button class="btn small secondary" type="button" data-pw-gen-alnum>Generate</button>
                                </div>
                                <div class="pw-meter" aria-hidden="true"><span></span><span></span><span></span></div>
                                <button class="btn small mt" type="submit">Set password</button>
                                <p class="help">Naya password sirf ek baar upar wale card me dikhega.</p>
                            </form>
                        </details>
                    </td>
                </tr>
                @endcan
            @empty
                <tr><td colspan="4" class="empty">No database users yet — neeche se pehla user banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('databases.manage')
{{-- add new user (cPanel Add New User) --}}
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Add New User</h3>
    <form method="post" action="{{ route('mysql-users.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="dbu-name">Username</label>
                <div class="row">
                    <span class="mono muted">{{ $account->username }}_</span>
                    <input id="dbu-name" name="user" value="{{ old('user') }}" maxlength="16" required placeholder="appuser" style="flex:1">
                </div>
                <label for="dbu-host">Host</label>
                <input id="dbu-host" name="host" value="{{ old('host', 'localhost') }}" maxlength="190" placeholder="localhost">
                <p class="help"><span class="mono">localhost</span> (same server) · IPv4 · FQDN · <span class="mono">%</span> = anywhere (Remote MySQL ke saath)</p>
            </div>
            <div>
                <label for="dbu-pw">Password <span class="muted">(blank = strong random; letters+numbers 10–64)</span></label>
                <div class="row" data-pw>
                    <input id="dbu-pw" name="password" type="password" pattern="[A-Za-z0-9]{10,64}" autocomplete="new-password" style="flex:1; min-width:180px">
                    <button class="btn small secondary" type="button" data-pw-show>Show</button>
                    <button class="btn small secondary" type="button" data-pw-gen-alnum>Generate</button>
                </div>
                <div class="pw-meter" aria-hidden="true"><span></span><span></span><span></span></div>
                <label>Grant on databases (optional)</label>
                <div class="row" style="flex-wrap:wrap; gap:8px">
                    @forelse ($databases as $database)
                        <label class="check" style="margin:0"><input type="checkbox" name="databases[]" value="{{ $database->id }}"> <span class="mono">{{ $database->fullName($account->username) }}</span></label>
                    @empty
                        <span class="muted">pehle <a href="{{ route('mysql.index') }}">database banao</a></span>
                    @endforelse
                </div>
                <button class="btn mt" type="submit">+ Create User</button>
            </div>
        </div>
        @error('user')<p class="error">{{ $message }}</p>@enderror
        @error('password')<p class="error">Password: sirf letters+numbers, 10–64 chars.</p>@enderror
        @error('host')<p class="error">{{ $message }}</p>@enderror
    </form>
</div>

{{-- add user to database (cPanel Add User To Database) --}}
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Add User To Database</h3>
    <form method="post" action="{{ route('mysql-users.grant') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="grant-user">User</label>
                <select id="grant-user" name="mysql_user_id" required style="min-width:200px">
                    @forelse ($users as $user)
                        <option value="{{ $user->id }}">{{ $account->username . '_' . $user->name . '@' . $user->host }}</option>
                    @empty
                        <option value="" disabled>No users</option>
                    @endforelse
                </select>
            </div>
            <div>
                <label for="grant-db">Database</label>
                <select id="grant-db" name="mysql_database_id" required style="min-width:200px">
                    @forelse ($databases as $database)
                        <option value="{{ $database->id }}">{{ $database->fullName($account->username) }}</option>
                    @empty
                        <option value="" disabled>No databases</option>
                    @endforelse
                </select>
            </div>
            <button class="btn" type="submit">Grant ALL PRIVILEGES</button>
        </div>
        @error('database')<p class="error">{{ $message }}</p>@enderror
        <p class="help">cPanel jaisa ALL PRIVILEGES ek database par.</p>
    </form>
</div>

{{-- D14: revoke privileges (grant ka ulta) --}}
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'ban', 'cls' => 'hico']) Revoke User From Database</h3>
    <form method="post" action="{{ route('mysql-users.revoke') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="revoke-user">User</label>
                <select id="revoke-user" name="mysql_user_id" required style="min-width:200px">
                    @forelse ($users as $user)
                        <option value="{{ $user->id }}">{{ $account->username . '_' . $user->name . '@' . $user->host }}</option>
                    @empty
                        <option value="" disabled>No users</option>
                    @endforelse
                </select>
            </div>
            <div>
                <label for="revoke-db">Database</label>
                <select id="revoke-db" name="mysql_database_id" required style="min-width:200px">
                    @forelse ($databases as $database)
                        <option value="{{ $database->id }}">{{ $database->fullName($account->username) }}</option>
                    @empty
                        <option value="" disabled>No databases</option>
                    @endforelse
                </select>
            </div>
            <button class="btn small danger" type="submit">Revoke privileges</button>
        </div>
        <p class="help">User aur database dono bache rehte hain — sirf access hatta hai (REVOKE ALL PRIVILEGES).</p>
    </form>
</div>
@endcan
@endif
@endsection
