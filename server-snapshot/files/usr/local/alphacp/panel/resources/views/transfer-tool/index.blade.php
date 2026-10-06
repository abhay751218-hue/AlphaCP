{{--
    RECONSTRUCTED 2026-10-06 — server par ye file maujood hai (server-snapshot/MANIFEST.txt),
    par alphacp-sync v1.2 ke secret-pattern false positive ki wajah se repo snapshot me nahi aayi.
    sync v1.3 pattern ko EOL-anchored karta hai; agla `sudo alphacp-sync` server ki asli file
    yahan la dega.

    NOTE: iska Feature test (TransferToolTest.php) bhi usi wajah se snapshot me nahi hai,
    isliye ye reconstruction sirf controller ke validation contract se banayi gayi hai —
    test se verified NAHI. Asli file aane ke baad ise replace kar do.
--}}
@extends('layouts.panel')

@section('title', 'Transfer Tool')
@section('subtitle', 'cPanel account ko is server par migrate karo (cpmove archive)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Transfer Tool</h3>
    <p class="help">Do tareeke: (1) operator cpmove archive server par khud rakh de, ya
        (2) purane server se seedha pull karo — pehle <em>Fingerprint lao</em>, verify karke pin karo,
        phir <em>Pull</em>. Import existing account ke home me hota hai, isliye pehle
        <a href="{{ route('accounts.create') }}">Create Account</a> zaroori hai.</p>

    @if ($row)
        <p class="mono mt">last: {{ $row->username }} ← {{ $row->source }}</p>
    @else
        <p class="empty">Abhi koi transfer record nahi.</p>
    @endif
</div>

@can('accounts.manage')
<div class="card mt">
    <h3>Import an archive already on this server</h3>
    @if ($archives === [])
        <p class="empty">Import drop dir me koi cpmove archive nahi mila.</p>
    @else
        <form method="post" action="{{ route('transfer-tool.store') }}" class="stack">
            @csrf
            <label>Username (isi server ka existing account)
                <input name="username" value="{{ old('username') }}" maxlength="16" required placeholder="custhost">
            </label>
            <label>Source server (FQDN)
                <input name="source" value="{{ old('source') }}" maxlength="190" required placeholder="old.example.com">
            </label>
            <label>Archive
                <select name="archive_path" required>
                    @foreach ($archives as $archive)
                        <option value="{{ $archive['path'] }}" @selected(old('archive_path') === $archive['path'])>
                            {{ $archive['path'] }} ({{ $archive['size_mb'] }} MB)
                        </option>
                    @endforeach
                </select>
            </label>
            <label>Expected sha256 (optional, 64 hex)
                <input name="sha256" value="{{ old('sha256') }}" maxlength="64" placeholder="">
            </label>
            @error('username')<p class="error">{{ $message }}</p>@enderror
            @error('source')<p class="error">{{ $message }}</p>@enderror
            <button class="btn" type="submit">Queue transfer</button>
        </form>
    @endif
</div>

<div class="card mt">
    <h3>Pull from a remote server — step 1: fingerprint</h3>
    <p class="help">Ye kuch download nahi karta. Sirf purane server ki SSH host key fingerprint
        laata hai, taaki aap use kisi bharosemand cheez se match karke pin kar sako.</p>
    <form method="post" action="{{ route('transfer-tool.probe') }}" class="stack">
        @csrf
        <label>Host (FQDN ya IP)
            <input name="host" value="{{ old('host') }}" maxlength="253" required placeholder="old.example.com">
        </label>
        <label>Port
            <input name="port" type="number" min="1" max="65535" value="{{ old('port', 22) }}">
        </label>
        <label>SSH user
            <input name="user" value="{{ old('user') }}" maxlength="32" required placeholder="root">
        </label>
        <label>Remote archive path (poora, .. ke bina)
            <input name="remote_path" value="{{ old('remote_path') }}" maxlength="4096" required
                   placeholder="/home/cpmove/cpmove-custhost.tar.gz">
        </label>
        @error('remote')<p class="error">{{ $message }}</p>@enderror
        <button class="btn secondary" type="submit">Fingerprint lao</button>
    </form>

    @if ($pullTask)
        <div class="kv mt">
            <div>probe task</div><div class="mono">#{{ $pullTask->id }} · {{ $pullTask->status }}</div>
            <div>result</div><div class="mono">{{ $pullTask->result ?: '—' }}</div>
            @if ($pullTask->error)
                <div>error</div><div class="mono error">{{ $pullTask->error }}</div>
            @endif
        </div>
    @endif
</div>

<div class="card mt">
    <h3>Pull from a remote server — step 2: transfer</h3>
    <form method="post" action="{{ route('transfer-tool.pull') }}" class="stack">
        @csrf
        <label>Host
            <input name="host" value="{{ old('host') }}" maxlength="253" required placeholder="old.example.com">
        </label>
        <label>Port
            <input name="port" type="number" min="1" max="65535" value="{{ old('port', 22) }}">
        </label>
        <label>SSH user
            <input name="user" value="{{ old('user') }}" maxlength="32" required placeholder="root">
        </label>
        <label>Remote archive path
            <input name="remote_path" value="{{ old('remote_path') }}" maxlength="4096" required>
        </label>
        <label>Auth
            <select name="auth">
                <option value="key" @selected(old('auth', 'key') === 'key')>private key</option>
                <option value="password" @selected(old('auth') === 'password')>password</option>
            </select>
        </label>
        <label>Private key (key auth) — panel me store nahi hota
            <textarea name="private_key" rows="4" maxlength="65536"></textarea>
        </label>
        <label>Password (password auth) — panel me store nahi hota
            <input name="password" type="password" maxlength="1024" autocomplete="new-password" value="">
        </label>
        <label>Pinned host key fingerprint
            <input name="host_fingerprint" value="{{ old('host_fingerprint') }}" maxlength="128" placeholder="SHA256:...">
        </label>
        <label class="row">
            <input type="checkbox" name="accept_host_key" value="1">
            <span>Accept first host key (kam safe — sirf tab jab fingerprint verify na ho sake)</span>
        </label>
        <label>Destination file name (.tar / .tar.gz / .tgz)
            <input name="dest_name" value="{{ old('dest_name') }}" maxlength="120" placeholder="">
        </label>
        <label>Expected sha256 (optional, 64 hex)
            <input name="sha256" value="{{ old('sha256') }}" maxlength="64">
        </label>
        <label>Speed limit (KB/s, 0 = unlimited)
            <input name="max_kbps" type="number" min="0" max="1000000" value="{{ old('max_kbps', 0) }}">
        </label>
        <label class="row">
            <input type="checkbox" name="overwrite" value="1">
            <span>Overwrite existing archive in drop dir</span>
        </label>
        @error('remote')<p class="error">{{ $message }}</p>@enderror
        @error('host_fingerprint')<p class="error">{{ $message }}</p>@enderror
        @error('dest_name')<p class="error">{{ $message }}</p>@enderror
        @error('private_key')<p class="error">{{ $message }}</p>@enderror
        @error('password')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Pull archive</button>
    </form>
</div>
@endcan

<div class="card mt">
    <p class="help">Transfer ka status
        <a href="{{ route('transfer-review.index') }}">Review Transfers and Restores</a> par dikhta hai.</p>
</div>
@endsection
