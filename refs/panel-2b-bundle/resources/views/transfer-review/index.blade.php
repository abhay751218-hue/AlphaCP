@extends('layouts.panel')

@section('title', 'Review Transfers and Restores')
@section('subtitle', 'WHM review job — no tar, no rsync, no pipe')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Review transfers and restores</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/backup/review.json</span>. Copy later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->status }} · {{ $row->username }}</p>
    @else
        <p class="empty">No review job yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Queue review</h3>
    <form method="post" action="{{ route('transfer-review.store') }}" class="stack">
        @csrf
        <label>
            Status
            <select name="status" required>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(old('status', $row?->status ?? 'pending') === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Account username
            <input name="username" value="{{ old('username', $row?->username ?? '') }}" maxlength="16" required placeholder="alicehost">
        </label>
        @error('status')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Queue review</button>
    </form>
</div>
@endcan
@endsection
