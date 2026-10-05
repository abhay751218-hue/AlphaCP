@extends('layouts.panel')

@section('title', 'Backup')
@section('subtitle', 'Download, restore, or create backups of your account')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')

<div class="card">
    <h3>Create home archive</h3>
    <p class="help">home files only — database alag se backup karo</p>
    <form method="post" action="{{ route('backup.archive') }}" class="mt">
        @csrf
        <button class="btn" type="submit">Create home archive</button>
    </form>
</div>

<div class="card mt">
    <h3>Backup</h3>
    <p class="help">jobs.json — create a backup of your home directory or databases</p>
    <form method="post" action="{{ route('backup.store') }}" class="stack mt">
        @csrf
        <label>Kind<select name="kind" required>
            @foreach ($kinds as $kind)<option value="{{ $kind }}">{{ $kind }}</option>@endforeach
        </select></label>
        <label>Path (optional)<input name="path" value="{{ old('path') }}" maxlength="240" placeholder="public_html"></label>
        @error('kind')<p class="error">{{ $message }}</p>@enderror
        @error('path')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue backup</button>
    </form>
</div>

@if ($rows->count())
<div class="card mt">
    <h3>Backup jobs</h3>
    <table class="table">
        <thead><tr><th>ID</th><th>Kind</th><th>Path</th><th>Created</th></tr></thead>
        <tbody>
        @foreach ($rows as $row)
            <tr><td>{{ $row->id }}</td><td>{{ $row->kind }}</td><td class="mono">{{ $row->path ?? '—' }}</td><td>{{ $row->created_at }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if (count($archiveTasks))
<div class="card mt">
    <h3>Archives</h3>
    <table class="table">
        <thead><tr><th>Archive ID</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach ($archiveTasks as $task)
            @php $payload = json_decode((string)$task->payload, true) ?? []; @endphp
            <tr>
                <td class="mono">{{ $payload['archive_id'] ?? '—' }}</td>
                <td>{{ $task->status }}</td>
                <td>{{ $task->created_at }}</td>
                <td>@if ($task->status === 'success')<a class="btn small" href="{{ route('backup.archive-download', ['archiveId' => $payload['archive_id'] ?? '']) }}">Download</a>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="card mt">
    <h3>Restore from archive</h3>
    <p class="help">Restore files from a verified archive back into your account.</p>
    <form method="post" action="{{ route('backup.restore-archive') }}" class="stack mt">
        @csrf
        <label>Archive ID<input name="archive_id" value="{{ old('archive_id') }}" maxlength="32" required placeholder="32-char hex"></label>
        <label>Path (optional)<input name="path" value="{{ old('path') }}" maxlength="240" placeholder="public_html"></label>
        <label class="checkbox"><input type="checkbox" name="confirm" value="1" required> I confirm — this will overwrite existing files</label>
        @error('archive_id')<p class="error">{{ $message }}</p>@enderror
        @error('path')<p class="error">{{ $message }}</p>@enderror
        @error('confirm')<p class="error">{{ $message }}</p>@enderror
        <button class="btn danger" type="submit">Restore</button>
    </form>
</div>

@if (count($restoreTasks))
<div class="card mt">
    <h3>Recent restores</h3>
    <table class="table">
        <thead><tr><th>Archive ID</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
        @foreach ($restoreTasks as $task)
            @php $payload = json_decode((string)$task->payload, true) ?? []; @endphp
            <tr><td class="mono">{{ $payload['archive_id'] ?? '—' }}</td><td>{{ $task->status }}</td><td>{{ $task->created_at }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@endsection