@extends('layouts.panel')

@section('title', 'Backup Destinations')
@section('subtitle', 'Server Manager remote backup destination — apne archives doosre server par bhejo (SSH/scp)')

@section('actions')
    <a class="btn small secondary" href="{{ route('backup-config.index') }}">Backup Config</a>
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@php
    $lastJob = $jobs->first();
    $lastResult = $lastJob ? json_decode((string) ($lastJob->result ?? ''), true) : null;
    $lastResult = is_array($lastResult) ? $lastResult : [];
    $lastPubkey = (string) ($lastResult['destination']['public_key'] ?? '');
    $lastFiles = $lastResult['files'] ?? [];
@endphp

<div class="card">
    <h3>Remote destinations</h3>
    <p class="help">
        Har archive <span class="mono">{{ config('acp.home') }}/backups/accounts/&lt;user&gt;/</span> se
        door ke server par jata hai — <strong>atomic</strong> (<span class="mono">.part</span> upload, phir
        remote <span class="mono">sha256sum</span> match ke baad rename). Host key <strong>pin</strong> zaroori
        hai: badle to (MITM / server reinstall) push apne aap ruk jata hai.
    </p>
    <p class="help">
        Private key / password panel ke database me kabhi nahi aate — wo agent ke paas
        <span class="mono">{{ config('acp.home') }}/etc/backup-keys/</span> me 0600 file me rehte hain.
    </p>

    @if ($destinations->isEmpty())
        <p class="empty">Koi destination abhi tak nahi. Neeche form se add karo.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Target</th>
                        <th>Auth</th>
                        <th>Pinned host key</th>
                        <th>Last test</th>
                        <th>Last push</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($destinations as $d)
                        <tr>
                            <td class="mono">
                                {{ $d->name }}
                                @if (!$d->enabled) <span class="mono">(disabled)</span> @endif
                            </td>
                            <td class="mono">{{ $d->username }}&#64;{{ $d->host }}:{{ $d->path }}</td>
                            <td class="mono">{{ $d->auth_type }}</td>
                            <td class="mono" style="max-width: 22ch; overflow-wrap: anywhere;">{{ $d->host_fingerprint ?: '—' }}</td>
                            <td class="mono">
                                @if ($d->last_test_at)
                                    {{ $d->last_test_ok ? 'ok' : 'FAIL' }} · {{ $d->last_test_at->format('Y-m-d H:i') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="mono">
                                @if ($d->last_push_at)
                                    {{ $d->last_push_at->format('Y-m-d H:i') }}
                                    @if ($d->last_push_message) · {{ \Illuminate\Support\Str::limit($d->last_push_message, 40) }} @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <div class="row-actions">
                                    <form method="post" action="{{ route('backup-destinations.test') }}">
                                        @csrf
                                        <input type="hidden" name="name" value="{{ $d->name }}">
                                        <button class="btn small secondary" type="submit">Test</button>
                                    </form>
                                    <form method="post" action="{{ route('backup-destinations.browse') }}">
                                        @csrf
                                        <input type="hidden" name="name" value="{{ $d->name }}">
                                        <button class="btn small secondary" type="submit">List</button>
                                    </form>
                                    <form method="post" action="{{ route('backup-destinations.destroy', ['name' => $d->name]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn small danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@can('accounts.manage')
<div class="card mt">
    <h3>Add / update a destination</h3>
    <p class="help">
        <strong>Pehle fingerprint lao:</strong> Transfer Tool → <em>Fingerprint lao</em> (wo sirf backup server ki
        SSH host key padhta hai, kuch download nahi). Uske baad yahan pin karke destination save karo.
    </p>
    <form method="post" action="{{ route('backup-destinations.store') }}" class="stack">
        @csrf
        <div class="grid-2">
            <label>
                Name (slug)
                <input name="name" value="{{ old('name') }}" maxlength="32" required placeholder="offsite1">
            </label>
            <label>
                Backup server host (FQDN ya IP)
                <input name="host" value="{{ old('host') }}" maxlength="253" required placeholder="backup.example.com">
            </label>
            <label>
                SSH port
                <input name="port" type="number" min="1" max="65535" value="{{ old('port', 22) }}">
            </label>
            <label>
                SSH user (backup server par)
                <input name="username" value="{{ old('username', 'backup') }}" maxlength="32" required placeholder="backup">
            </label>
        </div>
        <label>
            Remote directory (pehle se bana hoya, .. ke bina)
            <input name="path" value="{{ old('path') }}" maxlength="255" required placeholder="/srv/backups/alphacp">
        </label>
        <label>
            Auth
            <select name="auth" id="dest-auth">
                <option value="key" @selected(old('auth', 'key') === 'key')>SSH key (recommended — agent naya key banayega)</option>
                <option value="password" @selected(old('auth') === 'password')>Password (sshpass chahiye)</option>
            </select>
        </label>
        <label>
            SSH private key (optional — khali chhodoge to agent naya ed25519 key banayega)
            <textarea name="private_key" rows="3" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----">{{ old('private_key') }}</textarea>
        </label>
        <label>
            Password (sirf password auth ke liye)
            <input name="password" type="password" value="" autocomplete="new-password">
        </label>
        <label>
            Host key fingerprint (probe se)
            <input name="host_fingerprint" value="{{ old('host_fingerprint') }}" maxlength="128" required placeholder="SHA256:...">
        </label>
        <div class="grid-2">
            <label>
                Retention (days — record ke liye)
                <input name="retention_days" type="number" min="1" max="365" value="{{ old('retention_days', 30) }}">
            </label>
            <label class="checkbox">
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', true))>
                Enabled (cron is destination par archives bhejega)
            </label>
        </div>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        @error('host')<p class="error">{{ $message }}</p>@enderror
        @error('host_fingerprint')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save destination</button>
    </form>
</div>
@endcan

@if ($lastPubkey !== '')
<div class="card mt">
    <h3>Install this key on the backup server</h3>
    <p class="help">
        Agent ne naya ed25519 key banaya hai. Ise backup server ke
        <span class="mono">~{{ $destinations->firstWhere('name', $lastResult['destination']['name'] ?? '')?->username ?: 'backup' }}/.ssh/authorized_keys</span>
        me ek line me daal do — tab hi push chalega.
    </p>
    <p class="mono" style="overflow-wrap: anywhere;">{{ $lastPubkey }}</p>
</div>
@endif

@can('accounts.manage')
<div class="card mt">
    <h3>Push an archive now</h3>
    <p class="help">
        Cron har ghante khud bhi bhejta hai (<span class="mono">alphacp:backup-destination-push</span>, har
        archive ek hi baar). Yahan se ek archive turant bhej sakte ho.
    </p>
    @if ($archives === [])
        <p class="empty">Koi completed archive is server par nahi — pehle Accounts → Backup se archive banao.</p>
    @else
        <form method="post" action="{{ route('backup-destinations.push') }}" class="stack">
            @csrf
            <div class="grid-2">
                <label>
                    Destination
                    <select name="name" required>
                        @foreach ($destinations as $d)
                            <option value="{{ $d->name }}">{{ $d->name }} ({{ $d->host }})</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Archive
                    <select name="archive" required>
                        @foreach ($archives as $archive)
                            <option value="{{ $archive['username'] }}:{{ $archive['archive_id'] }}">
                                {{ $archive['username'] }}/{{ $archive['file'] }}
                                @if ($archive['size']) ({{ number_format($archive['size'] / 1048576, 1) }} MiB) @endif
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
            @error('archive')<p class="error">{{ $message }}</p>@enderror
            <button class="btn" type="submit">Push archive</button>
        </form>
    @endif
</div>
@endcan

@if ($lastFiles !== [])
<div class="card mt">
    <h3>Last listing (destination par maujood archives)</h3>
    <p class="mono">{{ $lastResult['name'] ?? '' }} · {{ $lastResult['path'] ?? '' }}</p>
    <div class="table-wrap mt">
        <table>
            <tbody>
                @foreach ($lastFiles as $file)
                    <tr><td class="mono">{{ $file }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card mt">
    <h3>Job history (backup.destination)</h3>
    @if ($jobs->isEmpty())
        <p class="empty">Abhi koi task nahi chala.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>#</th><th>Action</th><th>Status</th><th>Result</th><th>When</th></tr>
                </thead>
                <tbody>
                    @foreach ($jobs as $job)
                        @php
                            $payload = json_decode((string) ($job->payload ?? ''), true);
                            $payload = is_array($payload) ? $payload : [];
                            $result = json_decode((string) ($job->result ?? ''), true);
                            $result = is_array($result) ? $result : [];
                            $summary = $result['file'] ?? ($result['host'] ?? ($result['name'] ?? ''));
                        @endphp
                        <tr>
                            <td class="mono">{{ $job->id }}</td>
                            <td class="mono">{{ $payload['action'] ?? '—' }}</td>
                            <td class="mono">{{ $job->status }}</td>
                            <td class="mono" style="overflow-wrap: anywhere;">
                                {{ \Illuminate\Support\Str::limit((string) $summary, 60) }}
                                @if (($job->status ?? '') === 'failed' && $job->error)
                                    <span class="error">{{ \Illuminate\Support\Str::limit((string) $job->error, 160) }}</span>
                                @endif
                            </td>
                            <td class="mono">{{ $job->created_at }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($pushes->isNotEmpty())
        <p class="help mt">Ledger (kaunsa archive kahan gaya):</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Destination</th><th>Archive</th><th>Status</th><th>Task</th></tr></thead>
                <tbody>
                    @foreach ($pushes as $push)
                        <tr>
                            <td class="mono">{{ $push->destination?->name ?? ('#' . $push->destination_id) }}</td>
                            <td class="mono">{{ $push->username }}/{{ $push->file }}</td>
                            <td class="mono">{{ $push->status }}</td>
                            <td class="mono">{{ $push->task_id ? '#' . $push->task_id : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
