@extends('layouts.panel')

@section('title', 'Transfer or Restore a cPanel Account')
@section('subtitle', 'WHM cPanel archive import — real home swap, pre-restore copy kept')

@section('actions')
    <a class="btn small secondary" href="{{ route('transfer-review.index') }}">Job history</a>
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Last cPanel import</h3>
    <p class="help">Import server-side tarball se hota hai (cpmove upload panel ke through nahi). Path agent khud verify karta hai.</p>
    @if ($row)
        <p class="mono mt">{{ $row->action }} · {{ $row->username }}</p>
    @else
        <p class="empty">No cPanel account job yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Import a cPanel account archive</h3>
    <p class="help">
        <span class="mono">cpmove-&lt;user&gt;.tar.gz</span> ya legacy
        <span class="mono">backup-*.tar.gz</span> ko pehle server par rakho (SFTP/root), phir yahan poora path do —
        e.g. <span class="mono">/home/cpmove-alicehost.tar.gz</span>. Import se sirf <strong>home</strong> aata hai;
        MySQL dumps / mail / DNS userdata job result me list honge (uske baad ke S10 steps).
    </p>
    <form method="post" action="{{ route('transfer-restore.store') }}" class="stack">
        @csrf
        <label>
            Action
            <select name="action" required>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(old('action', $row?->action ?? 'restore') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Account username
            <input name="username" value="{{ old('username', $row?->username ?? '') }}" maxlength="16" required placeholder="alicehost">
        </label>
        <label>
            Archive path on this server
            <input name="archive_path" list="cpanel-archives" value="{{ old('archive_path') }}" maxlength="255" required placeholder="/home/cpmove-alicehost.tar.gz">
        </label>
        @if ($archives !== [])
            <datalist id="cpanel-archives">
                @foreach ($archives as $archive)
                    <option value="{{ $archive['path'] }}"></option>
                @endforeach
            </datalist>
            <p class="help">{{ count($archives) }} archive(s) mile server par — neeche list bhi dekho.</p>
        @endif
        <label>
            SHA-256 (optional — safety ke liye)
            <input name="sha256" value="{{ old('sha256') }}" maxlength="64" placeholder="64 hex chars">
        </label>
        @error('action')<p class="error">{{ $message }}</p>@enderror
        @error('username')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue import</button>
        <p class="help">Destructive: account ka current home replace hota hai, aur <span class="mono">/home/.acp-prerestore-&lt;user&gt;-&lt;stamp&gt;</span> copy bach jaati hai.</p>
    </form>
</div>

@if ($archives !== [])
<div class="card mt">
    <h3>Detected archives</h3>
    <div class="table-wrap">
        <table>
            <tr><th>Path</th><th>Size</th></tr>
            @foreach ($archives as $archive)
                <tr>
                    <td class="mono">{{ $archive['path'] }}</td>
                    <td class="mono">{{ number_format($archive['size_mb'], 1) }} MB</td>
                </tr>
            @endforeach
        </table>
    </div>
    <p class="help mt">Scan sirf well-known migration paths (<span class="mono">/home</span>, <span class="mono">&lt;ACP home&gt;/incoming</span>) me hota hai.</p>
</div>
@endif
@endcan
@endsection
