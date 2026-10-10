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
        if ($isDir) {
            return match ($name) {
                'mail' => '✉️',
                'public_html', 'www' => '🌐',
                'public_ftp' => '🔀',
                'ssl', '.ssh' => '🔒',
                default => '📁',
            };
        }
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
    // D30: cPanel-jaisa mime-type column
    $fmMime = function (string $name, bool $isDir): string {
        if ($isDir) return 'httpd/unix-directory';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match ($ext) {
            'html', 'htm' => 'text/html',
            'php', 'phtml' => 'application/x-php',
            'css' => 'text/css',
            'js', 'mjs' => 'text/javascript',
            'json' => 'application/json',
            'png', 'gif', 'webp', 'bmp' => 'image/' . $ext,
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'gz', 'tgz' => 'application/x-gzip',
            'zip' => 'application/zip',
            'tar' => 'application/x-tar',
            'pdf' => 'application/pdf',
            'txt', 'md', 'log', 'conf', 'ini' => 'text/plain',
            'sql' => 'text/x-sql',
            default => 'text/x-generic',
        };
    };
    // D34: demo-1 friendly Type column
    $fmType = function (string $name, bool $isDir): string {
        if ($isDir) return 'Folder';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match (true) {
            in_array($ext, ['js','mjs'], true) => 'JavaScript',
            $ext === 'json' => 'JSON',
            in_array($ext, ['html','htm'], true) => 'HTML',
            in_array($ext, ['css','scss','less'], true) => 'CSS',
            in_array($ext, ['php','phtml'], true) => 'PHP',
            in_array($ext, ['png','jpg','jpeg','gif','webp','svg','ico','bmp'], true) => 'Image',
            in_array($ext, ['zip','gz','tgz','tar','bz2','xz','rar','7z'], true) => 'Archive',
            in_array($ext, ['txt','md','log','conf','ini','env','yml','yaml'], true) => 'Text',
            $ext === 'pdf' => 'PDF',
            in_array($ext, ['sql','db','sqlite'], true) => 'Database',
            $ext === '' => 'File',
            default => strtoupper($ext),
        };
    };
@endphp

{{-- ============ D34: File Manager — demo-1 cPanel-classic rebuild ============ --}}
<style>
.fm-head{background:#222c38;border-radius:8px;color:#fff;padding:12px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.fm-head .fm-brand{font-size:18px;font-weight:800;letter-spacing:.2px}
.fm-head .fm-brand span{color:#ff6c2c}
.fm-head .fm-ht{font-size:15px;opacity:.92;border-left:1px solid rgba(255,255,255,.25);padding-left:12px}
.fm-head input{width:min(260px,60vw);font-size:13px;padding:7px 12px;border-radius:6px;border:0;background:#fff;color:#20323f}
.fm-crumbs{margin:10px 2px 8px;font-size:14px}
.fm-crumbs a{color:#5b6b7b;text-decoration:none}
.fm-crumbs a:hover{color:#1d6fb8}
.fm-crumbs .cur{font-weight:700;color:#20323f}
.fm-bar{padding:6px 8px;display:flex;gap:2px;flex-wrap:wrap;align-items:center}
.fm-tb{padding:7px 10px;font-size:13px;color:#1d6fb8;text-decoration:none;border-radius:4px;display:inline-flex;gap:6px;align-items:center;border:0;background:none;cursor:pointer;font-weight:500}
.fm-tb:hover{background:rgba(29,111,184,.09)}
.fm-sep{width:1px;height:20px;background:rgba(128,144,160,.35);margin:0 6px}
.fm-shell{display:flex;gap:14px;align-items:flex-start;margin-top:10px}
.fm-tree{flex:0 0 220px;font-size:13px;max-height:72vh;overflow:auto;padding:12px}
.fm-tree ul{list-style:none;margin:4px 0 0;padding-left:14px}
.fm-tree>ul{padding-left:0}
.fm-tree a{color:#20323f;text-decoration:none;display:block;padding:5px 8px;border-radius:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fm-tree a:hover{background:rgba(29,111,184,.09)}
.fm-tree li.on>a{background:#1d6fb8;color:#fff;font-weight:600}
.fm-main{flex:1 1 auto;min-width:0}
#acp-files{width:100%}
#acp-files thead th{background:#f4f6f9;color:#20323f;font-weight:700;font-size:13px;white-space:nowrap}
#acp-files thead th[data-sort]{cursor:pointer}
#acp-files thead th[data-sort]:after{content:' \2195';color:#9aa7b4;font-size:11px}
#acp-files tbody tr:nth-child(even){background:rgba(128,144,160,.05)}
#acp-files tbody tr:hover{background:rgba(29,111,184,.07)}
#acp-files tbody tr.fm-sel{background:rgba(29,111,184,.15)!important}
.fm-ck-c{width:34px;text-align:center}
.fm-ck,.fm-ck-all{width:16px;height:16px;accent-color:#1d6fb8}
#fm-ctx{position:fixed;z-index:9000;min-width:200px;background:#fff;border:1px solid #cfd8e3;border-radius:6px;box-shadow:0 8px 24px rgba(10,25,41,.22);padding:4px 0;display:none}
#fm-ctx button{display:block;width:100%;text-align:left;background:none;border:0;padding:8px 14px;font-size:13px;cursor:pointer;color:#20323f}
#fm-ctx button:hover{background:rgba(29,111,184,.1)}
#fm-ctx button.danger{color:#c0392b}
#fm-ctx .sep{border-top:1px solid #e4e9f0;margin:4px 0}
#fm-toast{position:fixed;right:16px;bottom:16px;z-index:9100;background:#222c38;color:#fff;border-radius:8px;padding:12px 16px;min-width:min(300px,80vw);box-shadow:0 10px 28px rgba(0,0,0,.35);display:none;font-size:13px}
#fm-toast .bar{height:8px;background:rgba(255,255,255,.18);border-radius:4px;margin-top:8px;overflow:hidden;display:none}
#fm-toast .bar>span{display:block;height:100%;width:0;background:#ff6c2c;transition:width .2s}
@media(max-width:920px){.fm-tree{display:none}}
</style>

{{-- dark brand header (demo-1) --}}
<div class="fm-head">
    <span class="fm-brand"><span>Alpha</span>CP</span>
    <span class="fm-ht">File Manager</span>
    <span class="push"></span>
    <input id="fm-search-top" type="search" placeholder="Search files..." autocomplete="off"
           data-filter-rows="#acp-files tbody tr" aria-label="Search files">
</div>

{{-- breadcrumb row (demo-1) --}}
<p class="fm-crumbs">
    <a href="{{ route('files.index') }}">Home</a>
    <span class="muted">/</span> <a href="{{ route('files.index') }}">{{ $account->username }}</a>
    @php $acc = ''; @endphp
    @foreach ($crumbs as $ci => $c)
        @php $acc = trim($acc . '/' . $c, '/'); @endphp
        <span class="muted">/</span>
        @if ($ci === count($crumbs) - 1)
            <span class="cur">{{ $c }}</span>
        @else
            <a href="{{ route('files.index', ['path' => $acc]) }}">{{ $c }}</a>
        @endif
    @endforeach
</p>

{{-- blue icon toolbar (demo-1) --}}
<div class="card fm-bar" id="fm-toolbar" data-restore="{{ route('file-restoration.index') }}">
    @can('files.manage')
        <a class="fm-tb" href="#fm-newfile">📄 + File</a>
        <a class="fm-tb" href="#fm-newfolder">📁 + Folder</a>
        <span class="fm-sep" aria-hidden="true"></span>
        <button type="button" class="fm-tb" data-act="copy">📋 Copy</button>
        <button type="button" class="fm-tb" data-act="move">➡️ Move</button>
        <a class="fm-tb" href="#fm-upload">☁️ Upload</a>
        <button type="button" class="fm-tb" data-act="download">⬇️ Download</button>
        <span class="fm-sep" aria-hidden="true"></span>
        <button type="button" class="fm-tb" data-act="delete">🗑️ Delete</button>
        <button type="button" class="fm-tb" data-act="restore">↩️ Restore</button>
        <span class="fm-sep" aria-hidden="true"></span>
        <button type="button" class="fm-tb" data-act="rename">✏️ Rename</button>
        <button type="button" class="fm-tb" data-act="edit">📝 Edit</button>
        <button type="button" class="fm-tb" data-act="perm">🔑 Permissions</button>
        <button type="button" class="fm-tb" data-act="compress">🗜️ Compress</button>
        <button type="button" class="fm-tb" data-act="extract">📦 Extract</button>
    @endcan
</div>

<div class="fm-shell">
<aside class="card fm-tree" aria-label="Directory tree">
    <ul>
        <li class="{{ $path === '' ? 'on' : '' }}"><a href="{{ route('files.index') }}">🏠 Home</a>
            <ul>
            @foreach ($rootDirs ?? [] as $d)
                @php $dn = (string) ($d['name'] ?? ''); $activeRoot = $crumbs !== [] && $crumbs[0] === $dn; @endphp
                <li class="{{ $activeRoot ? 'on' : '' }}">
                    <a href="{{ route('files.index', ['path' => $dn]) }}">{{ $fmIcon($dn, true) }} {{ $dn }}</a>
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

<div class="card" style="margin-top:0">
    <div class="table-wrap">
        <table id="acp-files" data-path="{{ $path }}"
               data-url-rename="{{ route('files.rename') }}"
               data-url-destroy="{{ route('files.destroy') }}"
               data-url-chmod="{{ route('files.chmod') }}"
               data-url-compress="{{ route('files.compress') }}"
               data-url-extract="{{ route('files.extract') }}"
               data-url-copy="{{ route('files.copy') }}">
            <thead>
            <tr>
                <th class="fm-ck-c"><input type="checkbox" class="fm-ck-all" id="fm-ck-all" aria-label="Select all"></th>
                <th data-sort="name">Name</th>
                <th data-sort="size">Size</th>
                <th data-sort="mtime">Last Modified</th>
                <th>Type</th>
                <th>Permissions</th>
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
                    $modeShort = $mode !== '' ? substr($mode, -3) : ($isDir ? '755' : '644');
                    $rowPath = trim($path.'/'.($row['name'] ?? ''), '/');
                    $isArchive = ! $isDir && (str_ends_with((string) ($row['name'] ?? ''), '.tar.gz') || str_ends_with((string) ($row['name'] ?? ''), '.tgz') || str_ends_with((string) ($row['name'] ?? ''), '.zip'));
                @endphp
                <tr data-fm data-path="{{ $rowPath }}" data-name="{{ $row['name'] ?? '' }}" data-dir="{{ $isDir ? 1 : 0 }}" data-arch="{{ $isArchive ? 1 : 0 }}" data-size="{{ $isDir ? -1 : $bytes }}" data-mtime="{{ $mt }}" data-mode="{{ $modeShort }}"@if($isDir) data-open="{{ route('files.index', ['path' => $rowPath]) }}"@endif>
                    <td class="fm-ck-c"><input type="checkbox" class="fm-ck" aria-label="Select {{ $row['name'] ?? '' }}"></td>
                    <td class="mono">
                        {{ $fmIcon((string) ($row['name'] ?? ''), $isDir) }}
                        @if ($isDir)
                            <a href="{{ route('files.index', ['path' => $rowPath]) }}">{{ $row['name'] }}</a>
                        @else
                            {{ $row['name'] }}
                        @endif
                    </td>
                    <td class="mono muted">{{ $isDir ? '--' : $size }}</td>
                    <td class="mono muted" style="font-size:12px">{{ $mt > 0 ? date('Y-m-d H:i', $mt) : '—' }}</td>
                    <td class="muted" style="font-size:12px">{{ $fmType((string) ($row['name'] ?? ''), $isDir) }}</td>
                    <td class="mono muted">{{ $mode !== '' ? $mode : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Folder khali hai — upar toolbar se file/folder banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Row par tap/click = select · right-click (mobile: long-press) = menu · Compress = <span class="mono">.tar.gz</span> · Extract = <span class="mono">.tar.gz / .zip</span> · 64 MB+ uploads FTP/SFTP se · Paths home tak jailed (<span class="mono">..</span> blocked).</p>
</div>

@can('files.manage')
<div class="card mt" id="fm-upload">
    <h3>@include('partials.icons', ['icon' => 'send', 'cls' => 'hico']) Upload file <span class="muted mono">~/{{ $path }}</span></h3>
    <form id="fm-upload-form" method="post" action="{{ route('files.upload') }}" enctype="multipart/form-data">
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

<input type="hidden" id="fm-csrf" value="{{ csrf_token() }}">
<script src="/assets/fm.js?v=d34" defer></script>
@endif
@endsection
