@extends('layouts.panel')

@section('title', 'FTP Accounts')
@section('subtitle', 'Pure-FTPd virtual users — ek chroot home per FTP login')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('webdisk.index') }}">Web Disk</a>
@endsection

@section('content')
@if (! $enabled)
<div class="card">
    <p><span class="badge amber">not installed</span> <strong>FTP daemon not installed.</strong> Run the portable installer
    (<code>installer/ftp-accounts.sh</code>) to enable Pure-FTPd on this server.</p>
</div>
@endif

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'server', 'cls' => 'hico']) FTP accounts</h3>
        <div class="stat"><span class="num">{{ $rows->count() }}</span><span class="unit">accounts
            @if ($enabled)<span class="badge green">daemon live</span>@else<span class="badge amber">daemon off</span>@endif
        </span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Connect settings</h3>
        @if ($account)
            <p class="help" style="margin:6px 0 0">Login format: <code>{{ $account->username }}_suffix</code> ·
                home: <code>{{ $account->home_path }}/ftp/suffix</code> · port 21 (FTPS explicit TLS).</p>
        @else
            <p class="help" style="margin:6px 0 0">Port 21 — FileZilla/WinSCP me explicit FTPS use karo.</p>
        @endif
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'server', 'cls' => 'hico']) FTP Accounts</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search accounts…" data-filter-rows="#acp-ftp tbody tr" aria-label="Search FTP accounts">
    </div>
    <div class="table-wrap">
        <table id="acp-ftp">
            <thead><tr><th>Login</th><th>Home</th><th>Quota</th><th>Status</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td><code>{{ $row->username }}</code></td>
                    <td><code>{{ $row->home_path }}</code></td>
                    <td>@if ($row->quota_mb > 0)<span class="badge blue">{{ $row->quota_mb }} MB</span>@else<span class="badge green">unlimited</span>@endif</td>
                    <td><span class="badge {{ $row->status === 'active' ? 'green' : 'amber' }}">{{ $row->status }}</span></td>
                    <td class="right">
                        <form method="POST" action="{{ route('ftp.destroy', $row) }}" onsubmit="return confirm('Delete FTP account?')">
                            @csrf @method('DELETE')
                            <button class="btn small danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No FTP accounts yet — neeche se banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'server', 'cls' => 'hico']) Add FTP Account</h3>
    <form method="POST" action="{{ route('ftp.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="ftp-username">FTP login suffix</label>
                <input id="ftp-username" type="text" name="username" placeholder="deploys" pattern="[a-z][a-z0-9]{0,15}" required style="min-width:160px">
            </div>
            <div>
                <label for="ftp-password">Password</label>
                <input id="ftp-password" type="password" name="password" minlength="8" required style="min-width:180px">
            </div>
            <div>
                <label for="ftp-quota">Quota (MB, 0 = unlimited)</label>
                <input id="ftp-quota" type="number" name="quota_mb" min="0" max="102400" value="0" style="max-width:140px">
            </div>
            <button class="btn" type="submit">+ Create</button>
        </div>
        @if ($account)
        <p class="help">Full login: <code>{{ $account->username }}_suffix</code> · home: <code>{{ $account->home_path }}/ftp/suffix</code></p>
        @endif
    </form>
</div>
@endsection
