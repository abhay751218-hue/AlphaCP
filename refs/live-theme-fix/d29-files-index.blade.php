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
    // D28: cPanel-jaisa file-type icon map
    $fmIcon = function (string $name, bool $isDir): string {
        if ($isDir) return '📁';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match (true) {
            in_array($ext, ['png','jpg','jpeg','gif','webp','svg','ico','bmp'], true) => '🖼️',
            in_array($ext, ['zip','gz','tgz','tar','bz2','xz','rar','7z'], true) => '🗜️',
            in_array($ext, ['php','phtml'], true) => '🐘',
            in_array($ext, ['html','htm'], true) => '🌐',
            in_array($ext, ['css','scss','less'], true) => '🎨',
            in_array($ext, ['js','mjs','json','ts'], true) => '📜',
            in_array($ext, ['mp3','wav','ogg','flac','m4a'], true) => '🎵',
            in_array($ext, ['mp4','mkv','webm','avi','mov'], true) => '🎬',
            in_array($ext, ['pdf'], true) => '📕',
            in_array($ext, ['txt','md','log','conf','ini','env','yml','yaml'], true) => '📄',
            in_array($ext, ['sql','db','sqlite'], true) => '🗄️',
            default => '📄',
        };
    };
@endphp

{{-- D29: cPanel-parity flat toolbar + nav row + tree layout --}}
<style>
.fm-bar{padding:4px 8px;display:flex;gap:2px;flex-wrap:wrap;align-items:center}
.fm-tb{padding:6px 10px;font-size:13px;color:inherit;text-decoration:none;border-radius:4px;display:inline-flex;gap:6px;align-items:center;border:0;background:none;cursor:pointer}
.fm-tb:hover{background:rgba(29,111,184,.09)}
.fm-nav .fm-tb{color:#1d6fb8}
.fm-sep{width:1px;height:18px;background:rgba(128,144,160,.35);margin:0 6px}
.fm-shell{display:flex;gap:14px;align-items:flex-start;margin-top:14px}
.fm-tree{flex:0 0 220px;font-size:13px;max-height:72vh;overflow:auto;padding:10px}
.fm-tree ul{list-style:none;margin:4px 0 0;padding-left:12px}
.fm-tree>ul{padding-left:0}
.fm-tree a{color:inherit;text-decoration:none;display:block;padding:3px 6px;border-radius:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fm-tree a:hover{background:rgba(29,111,184,.09)}
.fm-tree li.on>a{background:rgba(29,111,184,.14);color:#1d6fb8;font-weight:600}
.fm-main{flex:1 1 auto;min-width:0}
.fm-main .card{margin-top:0}
#acp-files thead th{color:#1d6fb8;font-weight:600}
#acp-files tbody tr:nth-child(even){background:rgba(128,144,160,.06)}
.fm-go{display:inline-flex;gap:6px;align-items:center}
.fm-go input{width:min(260px,40vw);font-size:13px;padding:5px 8px}
@media(max-width:920px){.fm-tree{display:none}}
</style>

<div class="card fm-bar">
    @can('files.manage')
        <a class="fm-tb" href="#fm-newfile">＋ File</a>
        <a class="fm-tb" href="#fm-newfolder">＋ Folder</a>
        <span class="fm-sep" aria-hidden="true"></span>
        <a class="fm-tb" href="#fm-upload">⬆️ Upload</a>
        <a class="fm-tb" href="#fm-rename">✏️ Rename</a>
        <a class="fm-tb" href="#fm-rename">📦 Move</a>
        <span class="fm-sep" aria-hidden="true"></span>
        <a class="fm-tb" href="#fm-newfile">📝 Edit</a>
        <a class="fm-tb" href="#acp-files">🔐 Permissions</a>
        <a class="fm-tb" href="#acp-files">🗜️ Compress</a>
        <a class="fm-tb" href="#acp-files">📂 Extract</a>
        <a class="fm-tb" href="#acp-files">🗑️ Delete</a>
    @endcan
</div>

<div class="card fm-bar fm-nav mt" style="margin-top:8px">
    <form class="fm-go" method="get" action="{{ route('files.index') }}">
        <input name="path" value="{{ $path }}" placeholder="public_html" aria-label="Path">
        <button class="btn small secondary" type="submit">Go</button>
    </form>
    <span class="fm-sep" aria-hidden="true"></span>
    <a class="fm-tb" href="{{ route('files.index') }}">🏠 Home</a>
    @if ($path !== '')
        <a class="fm-tb" href="{{ route('files.index', $parent === '' ? [] : ['path' => $parent]) }}">⬆ Up One Level</a>
    @endif
    <a class="fm-tb" href="{{ route('files.index', $path === '' ? [] : ['path' => $path]) }}">🔄 Reload</a>
    <span class="push"></span>
    <input type="search" class="searchbox" style="width:min(220px,100%);font-size:13px" placeholder="Search this folder…"
           data-filter-rows="#acp-files tbody tr" aria-label="Filter files">
</div>

<div class="fm-shell">
<aside class="card fm-tree" aria-label="Directory tree">
    <ul>
        <li class="{{ $path === '' ? 'on' : '' }}"><a href="{{ route('files.index') }}">🏠 home/{{ $account->username }}</a>
            <ul>
            @foreach ($rootDirs ?? [] as $d)
                @php $dn = (string) ($d['name'] ?? ''); $activeRoot = $crumbs !== [] && $crumbs[0] === $dn; @endphp
                <li class="{{ $activeRoot ? 'on' : '' }}">
                    <a href="{{ route('files.index', ['path' => $dn]) }}">{{ $activeRoot ? '📂' : '📁' }} {{ $dn }}</a>
                    @if ($activeRoot && count($crumbs) > 1)
                        <ul>
                            @php $accp = $dn; @endphp
                            @foreach (array_slice($crumbs, 1) as $c)
                                @php $accp .= '/' . $c; @endphp
                                <li class="on"><a href="{{ route('files.index', ['path' => $accp]) }}">📂 {{ $c }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
            </ul>
        </li>
    </ul>
</aside>
<div class="fm-main">

<div class="card mt">
    <div class="row mb" style="flex-wrap:wrap">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'folder', 'cls' => 'hico']) File Manager — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Filter this folder…"
               data-filter-rows="#acp-files tbody tr" aria-label="Filter files">
    </div>

    {{-- breadcrumbs (cPanel-style) --}}
    <p class="mono" style="font-size:13px; margin-bottom:10px">
        <a href="{{ route('files.index') }}">🏠 home/{{ $account->username }}</a>
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
                <th>Size</th>
                <th>Last Modified</th>
                <th>Type</th>
                <th>Permissions</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @if ($path !== '')
                <tr>
                    <td colspan="6"><a href="{{ route('files.index', $parent === '' ? [] : ['path' => $parent]) }}">⬆️ .. (parent folder)</a></td>
                </tr>
            @endif
            @forelse ($entries as $row)
                @php
                    $isDir = ($row['type'] ?? '') === 'dir';
                    $bytes = (int) ($row['size'] ?? 0);
                    $size = $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : ($bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B');
                    $mt = (int) ($row['mtime'] ?? 0);
                    $mode = (string) ($row['mode'] ?? '');
                    $modeShort = $mode !== '' ? ltrim($mode, '0') : '';
                    $rowPath = trim($path.'/'.($row['name'] ?? ''), '/');
                    $isArchive = ! $isDir && (str_ends_with((string) ($row['name'] ?? ''), '.tar.gz') || str_ends_with((string) ($row['name'] ?? ''), '.tgz'));
                @endphp
                <tr>
                    <td class="mono">
                        {{ $fmIcon((string) ($row['name'] ?? ''), $isDir) }}
                        @if ($isDir)
                            <a href="{{ route('files.index', ['path' => $rowPath]) }}">{{ $row['name'] }}/</a>
                        @else
                            {{ $row['name'] }}
                        @endif
                    </td>
                    <td class="mono muted">{{ $isDir ? '—' : $size }}</td>
                    <td class="mono muted" style="font-size:12px">{{ $mt > 0 ? date('M j, Y H:i', $mt) : '—' }}</td>
                    <td class="muted">{{ $isDir ? 'folder' : (strtolower(pathinfo((string) ($row['name'] ?? ''), PATHINFO_EXTENSION)) ?: 'file') }}</td>
                    <td class="mono muted">{{ $modeShort !== '' ? $modeShort : '—' }}</td>
                    <td class="right">
                        @can('files.manage')
                            <div class="row" style="justify-content:flex-end; gap:6px; flex-wrap:wrap">
                            <form method="post" action="{{ route('files.chmod') }}" class="row" style="gap:4px">
                                @csrf
                                <input type="hidden" name="path" value="{{ $rowPath }}">
                                <select name="mode" style="min-width:86px" aria-label="Permissions">
                                    @foreach (['644','600','640','664','755','750','700','775'] as $m)
                                        <option value="{{ $m }}" @selected($m === ($modeShort !== '' ? $modeShort : ($isDir ? '755' : '644')))>{{ $m }}</option>
                                    @endforeach
                                </select>
                                <button class="btn small secondary" type="submit">chmod</button>
                            </form>
                            @if ($isArchive)
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
                                <input type="hidden" name="path" value="{{ $rowPath }}">
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                            </div>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Folder khali hai — upar toolbar se file/folder banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Upload neeche se (64 MB tak) · usse bada FTP/SFTP se. Compress = <span class="mono">.tar.gz</span> · Paths account home tak jailed (<span class="mono">..</span> blocked).</p>
</div>

@can('files.manage')
{{-- D15: browser upload --}}
<div class="card mt" id="fm-upload">
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
    <div class="card" id="fm-newfolder">
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
    <div class="card" id="fm-rename">
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

<div class="card mt" id="fm-newfile">
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
</div>{{-- /fm-main --}}
</div>{{-- /fm-shell --}}
@endif
@endsection
