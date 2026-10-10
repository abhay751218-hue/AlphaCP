@extends('layouts.panel')

@section('title', 'Web Disk')
@section('subtitle', 'WebDAV accounts — files ko desktop/client se access karo')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('ftp.index') }}">FTP Accounts</a>
@endsection

@section('content')
@if ($error !== null)
    <div class="flash error">{{ $error }}</div>
@endif

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'hdd', 'cls' => 'hico']) Web Disk accounts</h3>
        <div class="stat"><span class="num">{{ count($accounts) }}</span><span class="unit">WebDAV logins</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Connect (WebDAV client)</h3>
        <p class="help" style="margin:6px 0 0">URL: <code>https://{{ request()->getHost() }}/webdisk/</code> ·
            Realm: <code>{{ $realm }}</code><br>Digest auth — wahi login/password jo yahan set kiya.
            Read-Only sirf padh sakta hai (write methods agent-side deny).</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'hdd', 'cls' => 'hico']) Web Disk Accounts</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-webdisk tbody tr" aria-label="Search accounts">
    </div>
    <div class="table-wrap">
        <table id="acp-webdisk">
            <thead><tr><th>Login</th><th>Permissions</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($accounts as $a)
                <tr>
                    <td class="mono">{{ $a['login'] ?? '' }}</td>
                    <td><span class="badge {{ ($a['permissions'] ?? '') === 'rw' ? 'green' : 'blue' }}">{{ ($a['permissions'] ?? '') === 'rw' ? 'Read-Write' : 'Read-Only' }}</span></td>
                    <td class="right">
                        <form method="POST" action="{{ route('webdisk.destroy', $a['login'] ?? '') }}" onsubmit="return confirm('Delete?')">
                            @csrf @method('DELETE')
                            <button class="btn small danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Koi Web Disk account nahi — neeche se banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'hdd', 'cls' => 'hico']) Create / Reset Web Disk Account</h3>
    <form method="POST" action="{{ route('webdisk.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="wd-login">Login</label>
                <input id="wd-login" type="text" name="login" placeholder="designer" pattern="[A-Za-z0-9._-]+" maxlength="60" required style="min-width:160px">
            </div>
            <div>
                <label for="wd-password">Password</label>
                <input id="wd-password" type="password" name="password" minlength="8" maxlength="128" required style="min-width:180px">
            </div>
            <div>
                <label for="wd-perm">Permissions</label>
                <select id="wd-perm" name="permissions" required style="min-width:150px">
                    <option value="rw">Read-Write</option>
                    <option value="ro">Read-Only</option>
                </select>
            </div>
            <button class="btn" type="submit">Save Account</button>
        </div>
        <p class="help">Maujooda login dobara save karna = password/permissions reset.</p>
    </form>
</div>
@endsection
