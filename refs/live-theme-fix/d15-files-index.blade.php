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
                            @php($rowPath = trim($path.'/'.($row['name'] ?? ''), '/'))
                            <div class="row" style="justify-content:flex-end; gap:6px; flex-wrap:wrap">
                            <form method="post" action="{{ route('files.chmod') }}" class="row" style="gap:4px">
                                @csrf
                                <input type="hidden" name="path" value="{{ $rowPath }}">
                                <select name="mode" style="min-width:86px">
                                    @foreach (['644','600','640','664','755','750','700','775'] as $m)
                                        <option value="{{ $m }}" @selected($m === ($isDir ? '755' : '644'))>{{ $m }}</option>
                                    @endforeach
                                </select>
                                <button class="btn small secondary" type="submit">chmod</button>
                            </form>
                            @if (! $isDir && (str_ends_with((string) ($row['name'] ?? ''), '.tar.gz') || str_ends_with((string) ($row['name'] ?? ''), '.tgz')))
                                <form method="post" action="{{ route('files.extract') }}">
                                    @csrf
                                    <input type="hidden" name="path" value="{{ $rowPath }}">
                                    <button class="btn small secondary" type="submit">Extract</button>
                                </form>
                            @else
                                <form method="post" action="{{ route('files.compress') }}">
                                    @csrf
                                    <input type="hidden" name="path" value="{{ $rowPath }}">
                                    <button class="btn small secondary" type="submit">Compress</button>
                                </form>
                            @endif
                            <form method="post" action="{{ route('files.destroy') }}" onsubmit="return confirm('Delete {{ $row['name'] ?? '' }}?{{ $isDir ? ' Folder + andar ka sab kuch delete hoga!' : '' }}')">
                                @csrf
                                <input type="hidden" name="path" value="{{ trim($path.'/'.($row['name'] ?? ''), '/') }}">
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                            </div>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Folder khali hai — neeche se folder/file banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Upload neeche se (64 MB tak) · usse bada FTP/SFTP se. Compress = <span class="mono">.tar.gz</span> · Paths account home tak jailed (<span class="mono">..</span> blocked).</p>
</div>

@can('files.manage')
{{-- D15: browser upload --}}
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Upload file <span class="muted mono">~/{{ $path }}</span></h3>
    <form method="post" action="{{ route('files.upload') }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="dir" value="{{ $path }}">
        <div class="row" style="align-items:flex-end; flex-wrap:wrap; gap:12px">
            <div>
                <label for="fm-file">File (max 64 MB)</label>
                <input id="fm-file" name="file" type="file" required>
            </div>
            <button class="btn" type="submit">⬆️ Upload</button>
        </div>
        @error('file')<p class="error">{{ $message }}</p>@enderror
        <p class="help" style="margin:8px 0 0">File isi folder me aayegi (ownership account user ki). Archive ho to upload ke baad <strong>Extract</strong> dabao.</p>
    </form>
</div>

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
