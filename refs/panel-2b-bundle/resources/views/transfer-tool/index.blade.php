@extends('layouts.panel')

@section('title', 'Transfer Tool')
@section('subtitle', 'WHM cPanel → AlphaCP — no tar, no rsync, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Transfer tool</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/backup/transfer.json</span>. Copy later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->username }} · {{ $row->source }}</p>
    @else
        <p class="empty">No transfer queued yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Queue transfer</h3>
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
        @error('source')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue transfer</button>
    </form>
</div>
@endcan
@endsection
