@extends('layouts.panel')

@section('title', 'FTP Accounts')
@section('subtitle', 'Pure-FTPd virtual users — ek chroot home per FTP login')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if (! $enabled)
<div class="card">
    <p><strong>FTP daemon not installed.</strong> Run the portable installer
    (<code>installer/ftp-accounts.sh</code>) to enable Pure-FTPd on this server.</p>
</div>
@endif

<div class="card">
    <h3>Add FTP Account</h3>
    <form method="POST" action="{{ route('ftp.store') }}">
        @csrf
        <label>FTP login suffix
            <input type="text" name="username" placeholder="deploys" pattern="[a-z][a-z0-9]{0,15}" required>
        </label>
        <label>Password
            <input type="password" name="password" minlength="8" required>
        </label>
        <label>Quota (MB, 0 = unlimited)
            <input type="number" name="quota_mb" min="0" max="102400" value="0">
        </label>
        @if ($account)
        <p class="muted">Full login: <code>{{ $account->username }}_suffix</code> · home: <code>{{ $account->home_path }}/ftp/suffix</code></p>
        @endif
        <button class="btn" type="submit">Create</button>
    </form>
</div>

<div class="card">
    <h3>Existing FTP Accounts ({{ $rows->count() }})</h3>
    @if ($rows->isEmpty())
        <p class="muted">No FTP accounts yet.</p>
    @else
        <table>
            <tr><th>Login</th><th>Home</th><th>Quota</th><th>Status</th><th></th></tr>
            @foreach ($rows as $row)
            <tr>
                <td><code>{{ $row->username }}</code></td>
                <td><code>{{ $row->home_path }}</code></td>
                <td>{{ $row->quota_mb > 0 ? $row->quota_mb . ' MB' : 'unlimited' }}</td>
                <td>{{ $row->status }}</td>
                <td>
                    <form method="POST" action="{{ route('ftp.destroy', $row) }}" style="display:inline" onsubmit="return confirm('Delete FTP account?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
