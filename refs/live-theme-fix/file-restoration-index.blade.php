@extends('layouts.panel')

@section('title', 'File Restoration')
@section('subtitle', 'Queue a relative path to restore — no tar, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('backup.index') }}">Backup</a>
    <a class="btn small secondary" href="{{ route('backup-wizard.index') }}">Backup Wizard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'refresh', 'cls' => 'hico']) Queued restores</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">paths queued</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Kaise kaam karta hai</h3>
        <p class="help" style="margin:6px 0 0">Queue JSON <span class="mono">~/etc/backup/restore.json</span> me jati hai.
            No tar/shell — relative paths only, pipe/path fail-closed.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'refresh', 'cls' => 'hico']) Restore Queue — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search paths…" data-filter-rows="#acp-restore tbody tr" aria-label="Search paths">
    </div>
    <div class="table-wrap">
        <table id="acp-restore">
            <thead><tr><th>Path</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr><td class="mono">@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) {{ $row->path }}</td></tr>
            @empty
                <tr><td class="empty">No restore paths yet — neeche se queue karo.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('files.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'refresh', 'cls' => 'hico']) Queue a Restore</h3>
    <form method="post" action="{{ route('file-restoration.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div style="flex:1; min-width:260px">
                <label for="fr-path">Path (relative)</label>
                <input id="fr-path" name="path" value="{{ old('path', '') }}" maxlength="240" required placeholder="public_html/index.php">
            </div>
            <button class="btn" type="submit">Queue Restore</button>
        </div>
        @error('path')<p class="error">{{ $message }}</p>@enderror
    </form>
</div>
@endcan
@endif
@endsection
