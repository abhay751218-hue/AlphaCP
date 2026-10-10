@extends('layouts.panel')

@section('title', 'Directory Privacy')
@section('subtitle', 'Password-protect a folder — Apache Basic Auth (bcrypt)')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('ip-blocker.index') }}">IP Blocker</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'lock', 'cls' => 'hico']) Protected folders</h3>
        <div class="stat"><span class="num">{{ count($entries) }}</span><span class="unit">folders password-protected</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Kaise kaam karta hai</h3>
        <p class="help" style="margin:6px 0 0"><span class="mono">AuthUserFile</span> <span class="mono">~/etc/privacy/</span> me rehti hai.
            Browser us folder par username/password puchhega. Path home ke andar hi — <span class="mono">..</span> fail-closed.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'lock', 'cls' => 'hico']) Protected Folders — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-privacy tbody tr" aria-label="Search folders">
    </div>
    <div class="table-wrap">
        <table id="acp-privacy">
            <thead><tr><th>Folder</th><th>Realm</th><th>Users</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($entries as $row)
                <tr>
                    <td class="mono">@include('partials.icons', ['icon' => 'folder', 'cls' => 'hico']) ~/{{ $row['path'] }}</td>
                    <td><span class="badge blue">{{ $row['realm'] }}</span></td>
                    <td class="mono">{{ implode(', ', array_column($row['users'], 'name')) }}</td>
                    <td class="right">
                        @can('privacy.manage')
                            <form method="post" action="{{ route('privacy.destroy') }}" onsubmit="return confirm('Remove this protection?')">
                                @csrf
                                <input type="hidden" name="path" value="{{ $row['path'] }}">
                                <button class="btn small danger" type="submit">Remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No protected folders yet — neeche se protect karo.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('privacy.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'lock', 'cls' => 'hico']) Protect a Folder</h3>
    <p class="help">Panel password ko bcrypt-hash karke bhejta hai — plaintext kabhi store nahi hota.</p>
    <form method="post" action="{{ route('privacy.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="path">Folder (relative)</label>
                <input id="path" name="path" required maxlength="240" placeholder="public_html/secret" value="{{ old('path') }}">
                <label for="realm">Protected area name</label>
                <input id="realm" name="realm" maxlength="64" placeholder="Private" value="{{ old('realm', 'Protected') }}">
            </div>
            <div>
                <label for="name">Username</label>
                <input id="name" name="name" required maxlength="32" placeholder="bob" value="{{ old('name') }}">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required minlength="5" maxlength="72">
                <button class="btn mt" type="submit">Protect Folder</button>
            </div>
        </div>
    </form>
</div>
@endcan
@endif
@endsection
