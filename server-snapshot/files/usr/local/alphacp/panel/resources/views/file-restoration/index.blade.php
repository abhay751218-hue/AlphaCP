@extends('layouts.panel')

@section('title', 'File Restoration')
@section('subtitle', 'Queue a relative path to restore — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers queue file restores here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>File Restoration — {{ $account->username }}</h3>
    <p class="help">JSON <span class="mono">~/etc/backup/restore.json</span>. No tar/shell. Relative paths only — pipe/path fail closed.</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Path</th>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td class="mono">{{ $row->path }}</td>
                </tr>
            @empty
                <tr><td class="empty">No restore paths yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('files.manage')
<div class="card mt">
    <h3>Queue restore</h3>
    <form method="post" action="{{ route('file-restoration.store') }}" class="stack">
        @csrf
        <label>
            Path
            <input name="path" value="{{ old('path', '') }}" maxlength="240" required placeholder="public_html/index.php">
        </label>
        @error('path')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue restore</button>
    </form>
</div>
@endcan
@endif
@endsection
