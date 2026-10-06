{{--
    RECONSTRUCTED 2026-10-06 — server par ye file maujood hai (server-snapshot/MANIFEST.txt
    me listed), par alphacp-sync v1.2 ki `-name backup ... -prune` rule ki wajah se repo
    snapshot me kabhi aayi hi nahi. Repo se panel banane par /backup 500 deta tha.
    sync v1.3 ye bug theek karta hai; agla `sudo alphacp-sync` server ki asli file
    is jagah la dega. Tab tak ye reconstruction BackupTest ke contract ko satisfy karti hai.
--}}
@extends('layouts.panel')

@section('title', 'Backup')
@section('subtitle', 'Full/partial backup, home archive aur restore — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Server-level schedule
        <a href="{{ route('backup-config.index') }}">Backup Configuration</a> par set hota hai.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Backup — {{ $account->username }}</h3>
    <p class="help">Job list JSON <span class="mono">~/etc/backup/jobs.json</span> me jaati hai.
        Agent sirf allowlisted <span class="mono">kind</span> ({{ implode(' / ', $kinds) }}) chalata hai —
        pipe ya path escape fail closed hai.</p>

    @if ($rows->isEmpty())
        <p class="empty">No backup jobs yet.</p>
    @else
        <div class="table-wrap mt">
            <table>
                <thead><tr><th>Kind</th><th>Path</th><th>Added</th></tr></thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="mono">{{ $row->kind }}</td>
                        <td class="mono">{{ $row->path !== '' ? $row->path : '—' }}</td>
                        <td class="mono">{{ $row->created_at }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@can('files.manage')
<div class="card mt">
    <h3>Add backup job</h3>
    <form method="post" action="{{ route('backup.store') }}" class="stack">
        @csrf
        <label>
            Kind
            <select name="kind" required>
                @foreach ($kinds as $kind)
                    <option value="{{ $kind }}" @selected(old('kind') === $kind)>{{ $kind }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Path (only for <span class="mono">home</span>, relative under home)
            <input name="path" value="{{ old('path', 'public_html') }}" maxlength="240" placeholder="public_html">
        </label>
        @error('kind')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Save backup job</button>
    </form>
</div>
@endcan

@can('files.manage')
<div class="card mt">
    <h3>Create home archive</h3>
    <p class="help">Ek verified <span class="mono">.tar.gz</span> banata hai — home files only
        (mail aur databases ke liye upar alag job banao). Archive checksum ke saath record hota hai
        aur yahin neeche download ke liye aata hai.</p>
    <form method="post" action="{{ route('backup.archive') }}" class="stack">
        @csrf
        @error('archive')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Create home archive</button>
    </form>

    @if ($archiveTasks === [])
        <p class="empty mt">No archives yet.</p>
    @else
        <div class="table-wrap mt">
            <table>
                <thead><tr><th>Task</th><th>Status</th><th>Created</th><th>Archive</th><th>Size</th><th></th></tr></thead>
                <tbody>
                @foreach ($archiveTasks as $task)
                    <tr>
                        <td class="mono">#{{ $task['id'] }}</td>
                        <td><span class="badge">{{ $task['status'] }}</span></td>
                        <td class="mono">{{ $task['created_at'] }}</td>
                        <td class="mono">{{ $task['archive_id'] ?? '—' }}</td>
                        <td class="mono">{{ $task['size_bytes'] !== null ? number_format($task['size_bytes'] / 1048576, 2) . ' MB' : '—' }}</td>
                        <td>
                            @if ($task['downloadable'])
                                <a class="btn small secondary" href="{{ route('backup.archive-download', ['archiveId' => $task['archive_id']]) }}">Download</a>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="card mt">
    <h3>Restore a home archive</h3>
    <p class="help">Destructive: files overwrite hoti hain. Purani files server par
        pre-restore copy ki tarah safe rehti hain. Sirf isi account ke verified archives chalte hain.</p>
    <form method="post" action="{{ route('backup.restore-archive') }}" class="stack">
        @csrf
        <label>
            Archive
            <select name="archive_id" required>
                <option value="">— pick a completed archive —</option>
                @foreach ($archiveTasks as $task)
                    @if ($task['downloadable'])
                        <option value="{{ $task['archive_id'] }}" @selected(old('archive_id') === $task['archive_id'])>
                            {{ $task['archive_id'] }} ({{ $task['created_at'] }})
                        </option>
                    @endif
                @endforeach
            </select>
        </label>
        <label>
            Restore into (relative under home)
            <input name="path" value="{{ old('path', 'public_html') }}" maxlength="240" placeholder="public_html">
        </label>
        <label class="row">
            <input type="checkbox" name="confirm" value="1" required>
            <span>I understand this overwrites files</span>
        </label>
        @error('archive_id')<p class="error">{{ $message }}</p>@enderror
        @error('path')<p class="error">{{ $message }}</p>@enderror
        @error('confirm')<p class="error">{{ $message }}</p>@enderror
        @error('restore')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue restore</button>
    </form>

    @if ($restoreTasks !== [])
        <div class="table-wrap mt">
            <table>
                <thead><tr><th>Task</th><th>Status</th><th>Created</th><th>Archive</th><th>Path</th><th>Error</th></tr></thead>
                <tbody>
                @foreach ($restoreTasks as $task)
                    <tr>
                        <td class="mono">#{{ $task['id'] }}</td>
                        <td><span class="badge">{{ $task['status'] }}</span></td>
                        <td class="mono">{{ $task['created_at'] }}</td>
                        <td class="mono">{{ $task['archive_id'] ?? '—' }}</td>
                        <td class="mono">{{ $task['path'] ?? '—' }}</td>
                        <td class="mono">{{ $task['error'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endcan
@endif
@endsection
