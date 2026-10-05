@extends('layouts.panel')

@section('title', 'Remote destinations')
@section('subtitle', 'Manage remote backup destinations (SSH/SCP)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')

<div class="card">
    <h3>Remote destinations</h3>
    <p class="help">Send your backup archives to a remote server over SSH. Host key pinning is mandatory.</p>

    @if (count($destinations))
    <table class="table mt">
        <thead><tr><th>Name</th><th>Host</th><th>Path</th><th>Auth</th><th>Retention</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach ($destinations as $dest)
            <tr>
                <td>{{ $dest->name }}</td>
                <td class="mono">{{ $dest->host }}:{{ $dest->port }}</td>
                <td class="mono">{{ $dest->path }}</td>
                <td>{{ $dest->auth }}</td>
                <td>{{ $dest->retention_days ?? '—' }} days</td>
                <td>
                    <form method="post" action="{{ route('backup-destinations.destroy', ['name' => $dest->name]) }}" style="display:inline">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Remove</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @else
    <p class="empty mt">No destinations configured yet.</p>
    @endif
</div>

<div class="card mt">
    <h3>Save destination</h3>
    <form method="post" action="{{ route('backup-destinations.store') }}" class="stack mt">
        @csrf
        <div class="grid-2">
            <label>Name<input name="name" value="{{ old('name') }}" maxlength="32" required pattern="^[a-z0-9][a-z0-9-]{0,31}$" placeholder="offsite1"></label>
            <label>Host<input name="host" value="{{ old('host') }}" maxlength="253" required placeholder="backup.example.com"></label>
            <label>Port<input name="port" type="number" value="{{ old('port', 22) }}" min="1" max="65535" required></label>
            <label>Username<input name="username" value="{{ old('username') }}" maxlength="32" required placeholder="backup"></label>
        </div>
        <label>Path<input name="path" value="{{ old('path') }}" maxlength="4096" required placeholder="/srv/backups/alphacp"></label>
        <label>Auth method<select name="auth" required><option value="key">SSH key</option><option value="password">Password</option></select></label>
        <label>Host key fingerprint (SHA256)<input name="host_fingerprint" value="{{ old('host_fingerprint') }}" maxlength="128" required placeholder="SHA256:..."></label>
        <label>Retention days<input name="retention_days" type="number" value="{{ old('retention_days', 30) }}" min="1" max="365"></label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        @error('host')<p class="error">{{ $message }}</p>@enderror
        @error('host_fingerprint')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save destination</button>
    </form>
</div>

@if (count($archives) && count($destinations))
<div class="card mt">
    <h3>Push archive to destination</h3>
    <form method="post" action="{{ route('backup-destinations.push') }}" class="stack mt">
        @csrf
        <label>Destination<select name="name" required>
            @foreach ($destinations as $dest)<option value="{{ $dest->name }}">{{ $dest->name }} ({{ $dest->host }})</option>@endforeach
        </select></label>
        <label>Archive<select name="archive" required>
            @foreach ($archives as $archive)<option value="{{ $archive }}">{{ $archive }}</option>@endforeach
        </select></label>
        <button class="btn" type="submit">Push now</button>
    </form>
</div>
@endif

@if (count($pushes))
<div class="card mt">
    <h3>Recent pushes</h3>
    <table class="table">
        <thead><tr><th>Destination</th><th>Archive</th><th>Status</th><th>Time</th></tr></thead>
        <tbody>
        @foreach ($pushes as $push)
            <tr><td>{{ $push->destination_name }}</td><td class="mono">{{ $push->archive_path }}</td><td>{{ $push->status }}</td><td>{{ $push->created_at }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if (count($jobs))
<div class="card mt">
    <h3>Agent jobs</h3>
    <table class="table">
        <thead><tr><th>Type</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
        @foreach ($jobs as $job)
            <tr><td class="mono">{{ $job->type }}</td><td>{{ $job->status }}</td><td>{{ $job->created_at }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@endsection