@extends('layouts.panel')

@section('title', 'Review Transfers and Restores')
@section('subtitle', 'Server Manager cpmove import job history — agent result ke saath')

@section('actions')
    <a class="btn small secondary" href="{{ route('transfer-tool.index') }}">Transfer Tool</a>
    <a class="btn small secondary" href="{{ route('transfer-restore.index') }}">Transfer or Restore</a>
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Transfer / restore jobs</h3>
    <p class="help">Har row ek <span class="mono">backup.cpanel</span> / <span class="mono">backup.transfer</span> / <span class="mono">db.restore</span> / <span class="mono">backup.pull</span> task hai — status aur agent result seedha task queue se aata hai.</p>
    <div class="table-wrap">
        <table>
            <tr><th>#</th><th>Type</th><th>Account</th><th>Status</th><th>Imported</th><th>Skipped sections</th><th>Source</th><th>When</th></tr>
            @forelse ($jobs as $job)
                <tr>
                    <td class="mono">{{ $job['id'] }}</td>
                    <td class="mono">{{ $job['type'] }}</td>
                    <td class="mono">{{ $job['username'] !== '' ? $job['username'] : '—' }}</td>
                    <td><span class="badge {{ $job['status'] === 'success' ? 'green' : ($job['status'] === 'failed' ? 'red' : 'amber') }}">{{ $job['status'] }}</span></td>
                    <td class="mono">
                        @if ($job['type'] === 'backup.pull')
                            {{ $job['status'] === 'success' && $job['bytes'] > 0 ? number_format($job['bytes'] / 1048576, 1) . ' MB pull' : '—' }}
                        @else
                            {{ $job['status'] === 'success' ? $job['files'] . ' files · ' . number_format($job['bytes'] / 1048576, 1) . ' MB' : '—' }}
                        @endif
                    </td>
                    <td class="mono">
                        @if ($job['type'] === 'backup.pull' && $job['fingerprint'] !== '')
                            host key {{ \Illuminate\Support\Str::limit($job['fingerprint'], 28) }}
                        @else
                            {{ $job['sections'] !== '' ? $job['sections'] : '—' }}
                        @endif
                    </td>
                    <td class="mono">{{ $job['source'] !== '' ? $job['source'] : '—' }}</td>
                    <td class="muted">{{ \App\Support\Panel::ago($job['created_at']) }}</td>
                </tr>
                @if ($job['error'] !== '')
                    <tr><td colspan="8" class="error mono">{{ $job['error'] }}</td></tr>
                @endif
            @empty
                <tr><td colspan="8" class="empty">Koi Account Panel import job nahi — Transfer Tool ya Transfer or Restore se queue karo.</td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card mt">
    <h3>Manual review note (legacy)</h3>
    <p class="help">JSON <span class="mono">/usr/local/alphacp/etc/backup/review.json</span>. Copy later. Pipe/shell fail closed.</p>
    @if ($row)
        <p class="mono mt">{{ $row->status }} · {{ $row->username }}</p>
    @else
        <p class="empty">No review job yet.</p>
    @endif
</div>

@can('accounts.view')
<div class="card mt">
    <h3>Queue review note</h3>
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
