@extends('layouts.panel')

@section('title', 'File and Directory Restoration')
@section('subtitle', 'Server Manager account path restore — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>File and directory restoration</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/backup/filedir.json</span>. Tar later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->username }} · {{ $row->path }}</p>
    @else
        <p class="empty">No file/directory restoration yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Queue restoration</h3>
    <form method="post" action="{{ route('file-directory-restoration.store') }}" class="stack">
        @csrf
        <label>
            Account username
            <input name="username" value="{{ old('username', $row?->username ?? '') }}" maxlength="16" required placeholder="alicehost">
        </label>
        <label>
            Relative path
            <input name="path" value="{{ old('path', $row?->path ?? '') }}" maxlength="240" required placeholder="mail/inbox">
        </label>
        @error('path')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue restoration</button>
    </form>
</div>
@endcan
@endsection
