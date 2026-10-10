@extends('layouts.panel')

@section('title', 'File Manager')
@section('subtitle', 'Account home only — safe paths, no shell')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer account panel</strong>. Customers manage files here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
@php
    $crumbs = $path === '' ? [] : explode('/', $path);
@endphp

<div class="card">
    <div class="row mb" style="flex-wrap:wrap">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'folder', 'cls' => 'hico']) File Manager — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Filter this folder…"
               data-filter-rows="#acp-files tbody tr" aria-label="Filter files">
    </div>

    {{-- breadcrumbs (cPanel-style) --}}
    <p class="mono" style="font-size:13px; margin-bottom:10px">
        <a href="{{ route('files.index') }}">🏠 home</a>
        @php $acc = ''; @endphp
        @foreach ($crumbs as $c)
            @php $acc = trim($acc . '/' . $c, '/'); @endphp
            <span class="muted">/</span> <a href="{{ route('files.index', ['path' => $acc]) }}">{{ $c }}</a>
        @endforeach
    </p>

    <div class="table-wrap">
        <table id="acp-files">
            <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Size</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @if ($path !== '')
                <tr>
                    <td colspan="4"><a href="{{ route('files.index', ['path' => $parent]) }}">⬆️ .. (parent folder)</a></td>
                </tr>
            @endif
            @forelse ($entries as $row)
                @php
                    $isDir = ($row['type'] ?? '') === 'dir';
                    $bytes = (int) ($row['size'] ?? 0);
                    $size = $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : ($bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B');
                @endphp
                <tr>
                    <td class="mono">
                        @if ($isDir)
                            📁 <a href="{{ route('files.index', ['path' => trim($path.'/'.$row['name'], '/')]) }}">{{ $row['name'] }}/</a>
                        @else
                            📄 {{ $row['name'] }}
                        @endif
                    </td>
                    <td class="muted">{{ $isDir ? 'folder' : 'file' }}</td>
                    <td class="mono muted">{{ $isDir ? '—' : $size }}</td>
                    <td class="right">
                        @can('files.manage')
                            <form method="post" action="{{ route('files.destroy') }}" onsubmit="return confirm('Delete {{ $row['name'] ?? '' }}?{{ $isDir ? ' Folder + andar ka sab kuch delete hoga!' : '' }}')">
                                @csrf
                                <input type="hidden" name="path" value="{{ trim($path.'/'.($row['name'] ?? ''), '/') }}">
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Folder khali hai — neeche se folder/file banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Backups ke liye <span class="mono">Backup</span> tool · bada upload FTP/SFTP se. Paths account home tak jailed hain (<span class="mono">..</span> blocked).</p>
</div>

@can('files.manage')
<div class="grid cols-2 mt">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'folder', 'cls' => 'hico']) New folder <span class="muted mono">~/{{ $path }}</span></h3>
        <form method="post" action="{{ route('files.mkdir') }}">
            @csrf
            <input type="hidden" name="dir" value="{{ $path }}">
            <div class="row">
                <input id="mkdir-name" name="name" required maxlength="80" placeholder="docs" style="flex:1">
                <button class="btn" type="submit">+ Folder</button>
            </div>
        </form>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'wrench', 'cls' => 'hico']) Rename / move</h3>
        <form method="post" action="{{ route('files.rename') }}">
            @csrf
            <label for="ren-from">From (relative)</label>
            <input id="ren-from" name="path" required maxlength="240" placeholder="{{ $path === '' ? 'public_html/old.txt' : $path.'/old.txt' }}">
            <label for="ren-to">To (relative)</label>
            <input id="ren-to" name="to" required maxlength="240" placeholder="{{ $path === '' ? 'public_html/new.txt' : $path.'/new.txt' }}">
            <button class="btn mt" type="submit">Rename</button>
        </form>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) New / edit file <span class="muted">(256 KiB max — text files)</span></h3>
    <form method="post" action="{{ route('files.write') }}">
        @csrf
        <input type="hidden" name="dir" value="{{ $path }}">
        <label for="file-name">Name</label>
        <input id="file-name" name="name" required maxlength="80" placeholder="hello.txt" style="max-width:320px">
        <label for="file-content">Content</label>
        <textarea id="file-content" name="content" rows="10" maxlength="262144" class="mono" spellcheck="false"></textarea>
        <button class="btn mt" type="submit">Save file</button>
    </form>
</div>
@endcan
@endif
@endsection
