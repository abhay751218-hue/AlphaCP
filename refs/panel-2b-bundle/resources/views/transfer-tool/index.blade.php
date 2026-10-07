@extends('layouts.panel')

@section('title', 'Transfer Tool')
@section('subtitle', 'Server Manager → AlphaCP — cpmove archive import, source host record ke saath')

@section('actions')
    <a class="btn small secondary" href="{{ route('transfer-review.index') }}">Job history</a>
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Last transfer</h3>
    <p class="help">Import server-side tarball se hota hai; source FQDN job result me record hota hai. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->username }} · {{ $row->source }}</p>
    @else
        <p class="empty">No transfer queued yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Import an account from an archive</h3>
    <p class="help">
        Purane server se <span class="mono">cpmove-&lt;user&gt;.tar.gz</span> yahan le aao (SFTP/root), phir path do —
        e.g. <span class="mono">/home/cpmove-alicehost.tar.gz</span>. Home import hota hai; MySQL/mail/DNS import aage ke S10 steps hain.
    </p>
    <form method="post" action="{{ route('transfer-tool.store') }}" class="stack">
        @csrf
        <label>
            Account username
            <input name="username" value="{{ old('username', $row?->username ?? '') }}" maxlength="16" required placeholder="alicehost">
        </label>
        <label>
            Source host (FQDN)
            <input name="source" value="{{ old('source', $row?->source ?? '') }}" maxlength="190" required placeholder="source.example.com">
        </label>
        <label>
            Archive path on this server
            <input name="archive_path" list="cpanel-archives" value="{{ old('archive_path') }}" maxlength="255" required placeholder="/home/cpmove-alicehost.tar.gz">
        </label>
        @if ($archives !== [])
            <datalist id="cpanel-archives">
                @foreach ($archives as $archive)
                    <option value="{{ $archive['path'] }}"></option>
                @endforeach
            </datalist>
        @endif
        <label>
            SHA-256 (optional — safety ke liye)
            <input name="sha256" value="{{ old('sha256') }}" maxlength="64" placeholder="64 hex chars">
        </label>
        @error('source')<p class="error">{{ $message }}</p>@enderror
        @error('username')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue transfer</button>
    </form>
</div>
@endcan

@can('accounts.view')
@php
    $pullResult = $pullTask ? json_decode((string) ($pullTask->result ?? ''), true) : null;
    $pullResult = is_array($pullResult) ? $pullResult : [];
    $probeFp = (string) ($pullResult['fingerprint'] ?? '');
    $probeHost = (string) ($pullResult['host'] ?? '');
@endphp
<div class="card mt">
    <h3>Purane server se archive khinch lao (SSH)</h3>
    <p class="help">
        Account Panel wale purane server se <span class="mono">cpmove-&lt;user&gt;.tar.gz</span> seedha yahan lao —
        haath se copy karne ki zaroorat nahi. Archive <span class="mono">{{ config('acp.home') }}/incoming</span>
        me aata hai (wahi drop dir jo neeche wale form me list hoti hai), phir upar wale form se import.
    </p>
    <p class="help">
        <strong>Do kadam:</strong> pehle <em>Fingerprint lao</em> — agent sirf purane server ki SSH host key
        padhta hai (koi download nahi). Fingerprint verify karo, phir wo pin karke <em>Archive lao</em>.
        Key badalne par (MITM / server reinstall) pull apne aap ruk jata hai.
    </p>

    @if ($probeFp !== '')
        <p class="mt mono">
            {{ $probeHost }} ka host key: <strong>{{ $probeFp }}</strong>
            @if (($pullResult['key_type'] ?? '') !== '') ({{ $pullResult['key_type'] }}) @endif
        </p>
        @if (($pullTask->status ?? '') !== 'success')
            <p class="error">Pichla task {{ $pullTask->status }} — error: {{ $pullTask->error }}</p>
        @endif
    @endif

    <form method="post" action="{{ route('transfer-tool.pull') }}" class="stack">
        @csrf
        <div class="grid-2">
            <label>
                Purane server ka host (FQDN ya IP)
                <input name="host" value="{{ old('host') }}" maxlength="253" required placeholder="old.example.com">
            </label>
            <label>
                SSH port
                <input name="port" type="number" min="1" max="65535" value="{{ old('port', 22) }}">
            </label>
            <label>
                SSH user (purane server par)
                <input name="user" value="{{ old('user', 'root') }}" maxlength="32" required placeholder="root">
            </label>
            <label>
                Remote archive path
                <input name="remote_path" value="{{ old('remote_path') }}" maxlength="4096" required placeholder="/home/cpmove-alicehost.tar.gz">
            </label>
        </div>

        <label>
            Auth
            <select name="auth" id="pull-auth">
                <option value="key" @selected(old('auth', 'key') === 'key')>SSH private key (recommended)</option>
                <option value="password" @selected(old('auth') === 'password')>Password (sshpass chahiye)</option>
            </select>
        </label>
        <label>
            SSH private key (PEM — paste karo; job history me save nahi hoti)
            <textarea name="private_key" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----">{{ old('private_key') }}</textarea>
        </label>
        <label>
            Password (sirf password auth ke liye)
            <input name="password" type="password" value="" autocomplete="new-password">
        </label>

        <label>
            Host key fingerprint (probe ke baad)
            <input name="host_fingerprint" value="{{ old('host_fingerprint', $probeFp) }}" maxlength="128" placeholder="SHA256:...">
        </label>
        <label class="checkbox">
            <input type="checkbox" name="accept_host_key" value="1" @checked(old('accept_host_key'))>
            Fingerprint pin nahi hai — pehli key accept kar lo (kam safe; log me warning)
        </label>

        <div class="grid-2">
            <label>
                Naam drop dir me (optional)
                <input name="dest_name" value="{{ old('dest_name') }}" maxlength="120" placeholder="cpmove-alicehost.tar.gz">
            </label>
            <label>
                Bandwidth limit (KB/s, optional)
                <input name="max_kbps" type="number" min="0" max="1000000" value="{{ old('max_kbps', 0) }}">
            </label>
            <label>
                SHA-256 (optional — download ke baad match check hota hai)
                <input name="sha256" value="{{ old('sha256') }}" maxlength="64" placeholder="64 hex chars">
            </label>
            <label class="checkbox">
                <input type="checkbox" name="overwrite" value="1" @checked(old('overwrite'))>
                Maujooda file overwrite karo
            </label>
        </div>

        @error('remote')<p class="error">{{ $message }}</p>@enderror
        @error('host_fingerprint')<p class="error">{{ $message }}</p>@enderror
        @error('dest_name')<p class="error">{{ $message }}</p>@enderror
        @error('private_key')<p class="error">{{ $message }}</p>@enderror
        @error('password')<p class="error">{{ $message }}</p>@enderror

        <div class="row">
            <button class="btn secondary" type="submit" formaction="{{ route('transfer-tool.probe') }}">1) Fingerprint lao (probe)</button>
            <button class="btn" type="submit">2) Archive lao (pull)</button>
        </div>
        <p class="help">Pehle upar ke 4 field bharo, phir <em>Fingerprint lao</em> — fingerprint aa jaye to wo
            <span class="mono">host key fingerprint</span> field me aa jata hai; verify karke <em>Archive lao</em> dabao.</p>
    </form>
</div>
@endcan
@endsection
