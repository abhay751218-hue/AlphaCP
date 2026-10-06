{{--
    RECONSTRUCTED 2026-10-06 — server par ye file maujood hai (server-snapshot/MANIFEST.txt),
    par alphacp-sync v1.2 ke secret-pattern false positive ki wajah se repo snapshot me nahi
    aayi (view me `PASSWORD = element.value` jaisi normal JS line "secret" samajh li gayi thi).
    sync v1.3 pattern ko EOL-anchored karta hai; agla `sudo alphacp-sync` server ki asli file
    yahan la dega. Tab tak ye reconstruction BackupDestinationsTest ke contract ko satisfy
    karti hai. Secret (private key / password) kabhi render nahi hota — sirf input field hai.
--}}
@extends('layouts.panel')

@section('title', 'Backup Destinations')
@section('subtitle', 'Remote destinations — archives kahan push honge')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Remote destinations</h3>
    <p class="help">Panel sirf <em>kahan</em> aur <em>kaun si host key</em> pin hai, yahi store karta hai.
        Private key ya password agent ke apne <span class="mono">0600</span> file me jaata hai aur
        har connection par pinned host key dobara verify hoti hai.</p>

    @if ($destinations->isEmpty())
        <p class="empty">No destination saved yet.</p>
    @else
        <div class="table-wrap mt">
            <table>
                <thead>
                    <tr><th>Name</th><th>Host</th><th>User</th><th>Path</th><th>Auth</th>
                        <th>Retention</th><th>Enabled</th><th>Last test</th><th>Last push</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($destinations as $destination)
                    <tr>
                        <td class="mono">{{ $destination->name }}</td>
                        <td class="mono">{{ $destination->host }}:{{ $destination->port }}</td>
                        <td class="mono">{{ $destination->username }}</td>
                        <td class="mono">{{ $destination->path }}</td>
                        <td class="mono">{{ $destination->auth_type }}</td>
                        <td class="mono">{{ $destination->retention_days }}d</td>
                        <td>{{ $destination->enabled ? 'yes' : 'no' }}</td>
                        <td class="mono">
                            {{ $destination->last_test_at ? $destination->last_test_at->toDateTimeString() : '—' }}
                            @if ($destination->last_test_at)
                                · {{ $destination->last_test_ok ? 'ok' : 'FAIL' }}
                            @endif
                        </td>
                        <td class="mono">{{ $destination->last_push_at ? $destination->last_push_at->toDateTimeString() : '—' }}</td>
                        <td class="row">
                            <form method="post" action="{{ route('backup-destinations.test') }}">
                                @csrf
                                <input type="hidden" name="name" value="{{ $destination->name }}">
                                <button class="btn small secondary" type="submit">Test</button>
                            </form>
                            <form method="post" action="{{ route('backup-destinations.browse') }}">
                                @csrf
                                <input type="hidden" name="name" value="{{ $destination->name }}">
                                <button class="btn small secondary" type="submit">Browse</button>
                            </form>
                            <form method="post" action="{{ route('backup-destinations.destroy', $destination->name) }}"
                                  onsubmit="return confirm('Remove destination {{ $destination->name }}?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn small secondary" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                    @if ($destination->last_test_message)
                        <tr><td colspan="10" class="mono muted">{{ $destination->last_test_message }}</td></tr>
                    @endif
                    @if ($destination->last_push_message)
                        <tr><td colspan="10" class="mono muted">push: {{ $destination->last_push_message }}</td></tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@can('accounts.manage')
<div class="card mt">
    <h3>Add / update a destination</h3>
    <p class="help">Host key pehle pin karo — bina fingerprint ke save fail hota hai.</p>
    <form method="post" action="{{ route('backup-destinations.store') }}" class="stack">
        @csrf
        <label>Name
            <input name="name" value="{{ old('name') }}" maxlength="32" required placeholder="offsite1">
        </label>
        <label>Host (FQDN ya IP)
            <input name="host" value="{{ old('host') }}" maxlength="253" required placeholder="backup.example.com">
        </label>
        <label>Port
            <input name="port" type="number" min="1" max="65535" value="{{ old('port', 22) }}" required>
        </label>
        <label>SSH user
            <input name="username" value="{{ old('username') }}" maxlength="32" required placeholder="backup">
        </label>
        <label>Remote path
            <input name="path" value="{{ old('path') }}" maxlength="255" required placeholder="/srv/backups/alphacp">
        </label>
        <label>Auth
            <select name="auth">
                <option value="key" @selected(old('auth', 'key') === 'key')>private key</option>
                <option value="password" @selected(old('auth') === 'password')>password</option>
            </select>
        </label>
        <label>Private key (key auth) — panel me store nahi hota
            <textarea name="private_key" rows="4" maxlength="65536" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
        </label>
        <label>Password (password auth) — panel me store nahi hota
            <input name="password" type="password" maxlength="1024" autocomplete="new-password" value="">
        </label>
        <label>Pinned host key fingerprint
            <input name="host_fingerprint" value="{{ old('host_fingerprint') }}" maxlength="128"
                   placeholder="SHA256:...">
        </label>
        <label>Retention (days)
            <input name="retention_days" type="number" min="1" max="365" value="{{ old('retention_days', 30) }}">
        </label>
        <label class="row">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', true))>
            <span>Enabled</span>
        </label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        @error('host')<p class="error">{{ $message }}</p>@enderror
        @error('host_fingerprint')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save destination</button>
    </form>
</div>

<div class="card mt">
    <h3>Push an archive now</h3>
    @if ($archives === [])
        <p class="empty">Koi pushable archive nahi — pehle Backup se home archive banao.</p>
    @elseif ($destinations->isEmpty())
        <p class="empty">Pehle ek destination save karo.</p>
    @else
        <form method="post" action="{{ route('backup-destinations.push') }}" class="stack">
            @csrf
            <label>Destination
                <select name="name" required>
                    @foreach ($destinations as $destination)
                        <option value="{{ $destination->name }}" @selected(old('name') === $destination->name)>
                            {{ $destination->name }} ({{ $destination->host }})
                        </option>
                    @endforeach
                </select>
            </label>
            <label>Archive
                <select name="archive" required>
                    @foreach ($archives as $archive)
                        <option value="{{ $archive['username'] }}:{{ $archive['archive_id'] }}"
                                @selected(old('archive') === $archive['username'] . ':' . $archive['archive_id'])>
                            {{ $archive['username'] }} · {{ $archive['file'] }}
                            @if ($archive['size'] !== null)
                                ({{ number_format($archive['size'] / 1048576, 2) }} MB)
                            @endif
                        </option>
                    @endforeach
                </select>
            </label>
            @error('archive')<p class="error">{{ $message }}</p>@enderror
            <button class="btn" type="submit">Push archive</button>
        </form>
    @endif

    @if ($pushes->isNotEmpty())
        <div class="table-wrap mt">
            <table>
                <thead><tr><th>#</th><th>Destination</th><th>Account</th><th>File</th><th>Status</th><th>Task</th><th>Message</th></tr></thead>
                <tbody>
                @foreach ($pushes as $push)
                    <tr>
                        <td class="mono">{{ $push->id }}</td>
                        <td class="mono">{{ $push->destination?->name ?? ('#' . $push->destination_id) }}</td>
                        <td class="mono">{{ $push->username }}</td>
                        <td class="mono">{{ $push->file }}</td>
                        <td><span class="badge">{{ $push->status }}</span></td>
                        <td class="mono">{{ $push->task_id ? '#' . $push->task_id : '—' }}</td>
                        <td class="mono">{{ $push->message ?: '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endcan

<div class="card mt">
    <h3>Recent agent jobs</h3>
    @if ($jobs->isEmpty())
        <p class="empty">No backup.destination jobs yet.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th>Task</th><th>Type</th><th>Status</th><th>Created</th><th>Error</th></tr></thead>
                <tbody>
                @foreach ($jobs as $job)
                    <tr>
                        <td class="mono">#{{ $job->id }}</td>
                        <td class="mono">{{ $job->type }}</td>
                        <td><span class="badge">{{ $job->status }}</span></td>
                        <td class="mono">{{ $job->created_at }}</td>
                        <td class="mono">{{ $job->error ?: '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
