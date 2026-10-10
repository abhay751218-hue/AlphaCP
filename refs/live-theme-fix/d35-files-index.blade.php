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
    // D35: colored file-type badges (demo-1) + yellow folder svg
    $fmBadge = function (string $name): array {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match (true) {
            in_array($ext, ['js','mjs','ts'], true) => ['fi-js', 'JS'],
            $ext === 'json' => ['fi-json', '{}'],
            in_array($ext, ['html','htm'], true) => ['fi-html', '<>'],
            in_array($ext, ['css','scss','less'], true) => ['fi-css', '#'],
            in_array($ext, ['php','phtml'], true) => ['fi-php', 'PHP'],
            in_array($ext, ['png','jpg','jpeg','gif','webp','svg','ico','bmp'], true) => ['fi-img', 'IMG'],
            in_array($ext, ['zip','gz','tgz','tar','bz2','xz','rar','7z'], true) => ['fi-zip', 'ZIP'],
            $ext === 'pdf' => ['fi-pdf', 'PDF'],
            in_array($ext, ['txt','md','log','conf','ini','env','yml','yaml'], true) => ['fi-txt', 'TXT'],
            in_array($ext, ['sql','db','sqlite'], true) => ['fi-db', 'DB'],
            default => ['fi-f', 'F'],
        };
    };
    $fmFold = '<svg class="fm-fold" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/></svg>';
@endphp

{{-- ============ D34: File Manager — demo-1 cPanel-classic rebuild ============ --}}
<style>
.fm-head{background:#222c38;border-radius:8px;color:#fff;padding:12px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.fm-head .fm-brand{font-size:18px;font-weight:800;letter-spacing:.2px}
.fm-head .fm-brand span{color:#ff6c2c}
.fm-head .fm-ht{font-size:15px;opacity:.92;border-left:1px solid rgba(255,255,255,.25);padding-left:12px}
.fm-logo svg{display:block}
.fm-srch{display:inline-flex;align-items:center;background:#fff;border-radius:6px;padding:0 10px;gap:7px}
.fm-srch input{border:0;outline:0;padding:8px 0;width:min(200px,46vw);font-size:13px;background:transparent;color:#20323f}
.fm-user{font-size:13px;opacity:.92}
.fm-tb svg{width:15px;height:15px;fill:currentColor;flex:0 0 auto}
.fm-fold{width:18px;height:18px;vertical-align:-4px;fill:#f6b73c}
.fi{display:inline-block;min-width:20px;height:16px;border-radius:3px;font-size:8px;font-weight:800;color:#fff;text-align:center;line-height:16px;padding:0 2px;vertical-align:1px;font-family:Arial,sans-serif;letter-spacing:.2px}
.fi-js{background:#f7df1e;color:#4a4a10}.fi-json{background:#3c873a}.fi-html{background:#e44d26}.fi-css{background:#264de4}.fi-php{background:#777bb3}.fi-img{background:#9b59b6}.fi-zip{background:#8e44ad}.fi-pdf{background:#c0392b}.fi-txt{background:#7f8c8d}.fi-db{background:#e67e22}.fi-f{background:#aab4bf}
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
#fm-ctx{position:fixed;z-index:9000;min-width:210px;background:#1f2733;border:1px solid #2d3b4e;border-radius:8px;box-shadow:0 10px 28px rgba(0,0,0,.45);padding:5px 0;display:none}
#fm-ctx button{display:block;width:100%;text-align:left;background:none;border:0;padding:9px 16px;font-size:13px;cursor:pointer;color:#dfe7f0}
#fm-ctx button:hover{background:rgba(255,255,255,.08)}
#fm-ctx button.danger{color:#ff6b5e}
#fm-ctx .sep{border-top:1px solid #2d3b4e;margin:4px 0}
#fm-toast{position:fixed;right:16px;bottom:16px;z-index:9100;background:#fff;color:#20323f;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;min-width:min(320px,84vw);box-shadow:0 12px 32px rgba(10,25,41,.28);display:none;font-size:13px}
#fm-toast .bar{height:8px;background:#edf1f5;border-radius:4px;margin-top:9px;overflow:hidden;display:none}
#fm-toast .bar>span{display:block;height:100%;width:0;background:#ff6c2c;transition:width .2s}
.fm-toast-row{display:flex;justify-content:space-between;align-items:center;margin-top:7px;font-size:12px;color:#5b6b7b}
#fm-toast-cancel{background:none;border:0;color:#ff6c2c;font-weight:700;cursor:pointer;font-size:12px;display:none}
#fm-modal{position:fixed;inset:0;background:rgba(10,20,30,.55);z-index:9200;display:flex;align-items:center;justify-content:center}
.fm-modal-card{background:#fff;border-radius:10px;width:min(430px,92vw);box-shadow:0 18px 50px rgba(0,0,0,.4);overflow:hidden}
.fm-modal-head{background:#ff6c2c;color:#fff;font-weight:700;font-size:15px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center}
.fm-modal-head button{background:none;border:0;color:#fff;font-size:20px;cursor:pointer;line-height:1}
.fm-modal-body{padding:16px}
.fm-modal-body label{font-size:13px;font-weight:600;display:block;margin-bottom:6px}
.fm-modal-body input{width:100%;padding:8px 10px;font-size:13px;border:1px solid #cfd8e3;border-radius:6px}
.fm-modal-foot{padding:0 16px 16px;display:flex;justify-content:flex-end;gap:8px}
@media(max-width:920px){.fm-tree{display:none}}
</style>

{{-- dark brand header (demo-1) --}}
<div class="fm-head">
    <span class="fm-logo" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26"><path fill="#ff6c2c" d="M12 2 2 20h5l5-9 5 9h5L12 2z"/><path fill="#ffb38a" d="M12 11.2 8.9 16.8h6.2L12 11.2z"/></svg></span>
    <span class="fm-brand"><span>Alpha</span>CP</span>
    <span class="fm-ht">AlphaCP File Manager</span>
    <span class="push"></span>
    <span class="fm-srch">
        <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="#8a97a5" d="M15.5 14h-.8l-.3-.3a6.5 6.5 0 1 0-.7.7l.3.3v.8l5 5 1.5-1.5-5-5zm-6 0a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9z"/></svg>
        <input id="fm-search-top" type="search" placeholder="Search files..." autocomplete="off"
               data-filter-rows="#acp-files tbody tr" aria-label="Search files">
    </span>
    <span class="fm-user">&#128100; {{ $account->username }} &#9662;</span>
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

{{-- blue icon toolbar (demo-1, svg icons) --}}
<div class="card fm-bar" id="fm-toolbar" data-restore="{{ route('file-restoration.index') }}">
    @can('files.manage')
        <a class="fm-tb" href="#fm-newfile"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-7-7zm-1 7V3.5L17.5 9H12zm-1 3v3H8v2h3v3h2v-3h3v-2h-3v-3h-2z"/></svg> + File</a>
        <a class="fm-tb" href="#fm-newfolder"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2zm1 5h2v3h3v2h-3v3h-2v-3H8v-2h3V9z"/></svg> + Folder</a>
        <span class="fm-sep" aria-hidden="true"></span>
        <button type="button" class="fm-tb" data-act="copy"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></svg> Copy</button>
        <button type="button" class="fm-tb" data-act="move"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11h12.17l-5.59-5.59L12 4l8 8-8 8-1.41-1.41L16.17 13H4v-2z"/></svg> Move</button>
        <a class="fm-tb" href="#fm-upload"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.35 10.04A7.49 7.49 0 0 0 12 4 7.48 7.48 0 0 0 5.36 8.04 6 6 0 0 0 6 20h13a5 5 0 0 0 .35-9.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg> Upload</a>
        <button type="button" class="fm-tb" data-act="download"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.35 10.04A7.49 7.49 0 0 0 12 4 7.48 7.48 0 0 0 5.36 8.04 6 6 0 0 0 6 20h13a5 5 0 0 0 .35-9.96zM17 13l-5 5-5-5h3V9h4v4h3z"/></svg> Download</button>
        <span class="fm-sep" aria-hidden="true"></span>
        <button type="button" class="fm-tb" data-act="delete"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg> Delete</button>
        <button type="button" class="fm-tb" data-act="restore"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 3a9 9 0 0 0-9 9H1l4 4 4-4H6a7 7 0 1 1 7 7v2a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg> Restore</button>
        <span class="fm-sep" aria-hidden="true"></span>
        <button type="button" class="fm-tb" data-act="rename"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg> Rename</button>
        <button type="button" class="fm-tb" data-act="edit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 19H5V5h7V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg> Edit</button>
        <button type="button" class="fm-tb" data-act="perm"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 10h-8.35A5.99 5.99 0 0 0 7 6a6 6 0 1 0 5.65 8H15l2 2 2-2 2 2 2-3-2-3zM7 14a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg> Permissions</button>
        <button type="button" class="fm-tb" data-act="compress"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.54 5.23 19.15 3.55A2 2 0 0 0 17.6 3H6.4c-.62 0-1.17.2-1.55.55L3.46 5.23A2 2 0 0 0 3 6.5V19a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5 6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z"/></svg> Compress</button>
        <button type="button" class="fm-tb" data-act="extract"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.54 5.23 19.15 3.55A2 2 0 0 0 17.6 3H6.4c-.62 0-1.17.2-1.55.55L3.46 5.23A2 2 0 0 0 3 6.5V19a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 9.5l5.5 5.5H14v2h-4v-2H6.5L12 9.5zM5.12 5l.81-1h12l.94 1H5.12z"/></svg> Extract</button>
    @endcan
</div>

<div class="fm-shell">
<aside class="card fm-tree" aria-label="Directory tree">
    <ul>
        <li class="{{ $path === '' ? 'on' : '' }}"><a href="{{ route('files.index') }}">&#9662; {!! $fmFold !!} Home</a>
            <ul>
            @foreach ($rootDirs ?? [] as $d)
                @php $dn = (string) ($d['name'] ?? ''); $activeRoot = $crumbs !== [] && $crumbs[0] === $dn; @endphp
                <li class="{{ $activeRoot ? 'on' : '' }}">
                    <a href="{{ route('files.index', ['path' => $dn]) }}">{!! $fmFold !!} {{ $dn }}</a>
                    @if ($activeRoot && count($crumbs) > 1)
                        <ul>
                            @php $accp = $dn; @endphp
                            @foreach (array_slice($crumbs, 1) as $c)
                                @php $accp .= '/' . $c; @endphp
                                <li class="on"><a href="{{ route('files.index', ['path' => $accp]) }}">{!! $fmFold !!} {{ $c }}</a></li>
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
                        @if ($isDir){!! $fmFold !!}@else @php [$fbc, $fbl] = $fmBadge((string) ($row['name'] ?? '')); @endphp<span class="fi {{ $fbc }}">{{ $fbl }}</span>@endif
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

<div id="fm-modal" hidden>
    <div class="fm-modal-card" role="dialog" aria-label="Extract Archive">
        <div class="fm-modal-head">Extract Archive <button type="button" id="fm-modal-x" aria-label="Close">&times;</button></div>
        <div class="fm-modal-body">
            <label for="fm-modal-to">Target directory:</label>
            <input id="fm-modal-to" type="text" autocomplete="off">
            <p class="muted" id="fm-modal-info" style="font-size:12px;margin:9px 0 0"></p>
        </div>
        <div class="fm-modal-foot">
            <button type="button" class="btn small secondary" id="fm-modal-cancel">Cancel</button>
            <button type="button" class="btn small" id="fm-modal-go">Extract</button>
        </div>
    </div>
</div>

<input type="hidden" id="fm-csrf" value="{{ csrf_token() }}">
<script src="/assets/fm.js?v=d35" defer></script>
@endif
@endsection
