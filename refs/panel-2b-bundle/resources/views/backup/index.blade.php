@extends('layouts.panel')

@section('title', 'Backup')
@section('subtitle', 'Create a downloadable home archive and manage backup job settings')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers create and download their own home archives here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Home archive — {{ $account->username }}</h3>
    <p class="help">Creates a real gzip-compressed tar archive of this account's home directory. Each completed archive is SHA-256 verified before download.</p>
    <p class="help"><strong>Scope:</strong> home files only. Mail and MySQL data are not included yet; their S7/S8 backends must become real before those can be backed up safely. Automated schedules, remote destinations, and restore are not enabled yet.</p>
    @can('files.manage')
    <form method="post" action="{{ route('backup.archive') }}" class="mt">
        @csrf
        <button class="btn" type="submit">Create home archive</button>
    </form>
    @endcan
    @error('archive')<p class="error">{{ $message }}</p>@enderror

    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Task</th>
                <th>Requested</th>
                <th>Status</th>
                <th>Archive</th>
            </tr>
            @forelse ($archiveTasks as $task)
                <tr>
                    <td class="mono">#{{ $task['id'] }}</td>
                    <td>{{ $task['created_at'] }}</td>
                    <td class="mono">{{ $task['status'] }}</td>
                    <td>
                        @if ($task['downloadable'])
                            {{ number_format($task['size_bytes'] / 1048576, 2) }} MiB
                            <a class="btn small secondary" href="{{ route('backup.archive-download', ['archiveId' => $task['archive_id']]) }}">Download verified archive</a>
                        @elseif ($task['status'] === 'failed')
                            Backup failed. Please contact the server administrator.
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No archives have been requested yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card mt">
    <h3>Backup job list — settings only</h3>
    <p class="help">This stores the selected home/mail/MySQL job configuration in <span class="mono">~/etc/backup/jobs.json</span>. It does not run archives or create mail/database dumps.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Kind</th>
                <th>Path</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->kind }}</td>
                    <td class="mono">{{ $row->path === '' ? '—' : $row->path }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No backup jobs configured.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('files.manage')
<div class="card mt">
    <h3>Save backup job settings</h3>
    <form method="post" action="{{ route('backup.store') }}" class="stack">
        @csrf
        <label>
            Kind
            <select name="kind" required>
                @foreach ($kinds as $kind)
                    <option value="{{ $kind }}" @selected(old('kind', 'home') === $kind)>{{ $kind }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Path (home only, optional)
            <input name="path" value="{{ old('path', '') }}" maxlength="240" placeholder="public_html">
        </label>
        @error('kind')<p class="error">{{ $message }}</p>@enderror
        <button class="btn secondary" type="submit">Save job settings</button>
    </form>
</div>
@endcan
@endif
@endsection
