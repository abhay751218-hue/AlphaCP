@extends('layouts.panel')

@section('title', 'Backup User Selection')
@section('subtitle', 'Server Manager accounts to include — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Backup user selection</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/backup/users.json</span>. Tar later. Pipe/shell fail closed.</p>
    @forelse ($rows as $row)
        <p class="mono mt">{{ $row->username }}</p>
    @empty
        <p class="empty">No backup users selected yet.</p>
    @endforelse
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Add backup user</h3>
    <form method="post" action="{{ route('backup-user-selection.store') }}" class="stack">
        @csrf
        <label>
            Account username
            <input name="username" value="{{ old('username') }}" maxlength="16" required placeholder="alicehost">
        </label>
        @error('username')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Add user</button>
    </form>
</div>
@endcan
@endsection
