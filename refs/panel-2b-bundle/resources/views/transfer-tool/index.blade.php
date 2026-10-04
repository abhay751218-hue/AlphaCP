@extends('layouts.panel')

@section('title', 'Transfer Tool')
@section('subtitle', 'WHM cPanel → AlphaCP — cpmove archive import, source host record ke saath')

@section('actions')
    <a class="btn small secondary" href="{{ route('transfer-review.index') }}">Job history</a>
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Last transfer</h3>
    <p class="help">Import server-side tarball se hota hai; source FQDN job result me record hota hai. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->username }} · {{ $row->source }}</p>
    @else
        <p class="empty">No transfer queued yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Import a cPanel account from an archive</h3>
    <p class="help">
        Purane server se <span class="mono">cpmove-&lt;user&gt;.tar.gz</span> yahan le aao (SFTP/root), phir path do —
        e.g. <span class="mono">/home/cpmove-alicehost.tar.gz</span>. Home import hota hai; MySQL/mail/DNS import aage ke S10 steps hain.
    </p>
    <form method="post" action="{{ route('transfer-tool.store') }}" class="stack">
        @csrf
        <label>
            Account username
            <input name="username" value="{{ old('username', $row?->username ?? '') }}" maxlength="16" required placeholder="alicehost">
        </label>
        <label>
            Source host (FQDN)
            <input name="source" value="{{ old('source', $row?->source ?? '') }}" maxlength="190" required placeholder="source.example.com">
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
        @endif
        <label>
            SHA-256 (optional — safety ke liye)
            <input name="sha256" value="{{ old('sha256') }}" maxlength="64" placeholder="64 hex chars">
        </label>
        @error('source')<p class="error">{{ $message }}</p>@enderror
        @error('username')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue transfer</button>
        <p class="help">Direct pull from the old server (SSH/API) abhi pending hai — abhi local archive import hota hai.</p>
    </form>
</div>
@endcan
@endsection
