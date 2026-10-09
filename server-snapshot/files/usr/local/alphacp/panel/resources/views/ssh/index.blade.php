@extends('layouts.panel')

@section('title', 'SSH Access')
@section('subtitle', 'Authorized keys + nologin/bash — account home only')

@section('actions')
    <a class="btn small secondary" href="{{ route('terminal.index') }}">Terminal</a>
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
        <h3>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Authorized keys</h3>
        <div class="stat"><span class="num">{{ count($keys) }}</span><span class="unit">keys ·
            shell <span class="badge {{ $shell === 'bash' ? 'green' : 'blue' }} mono">{{ $shell }}</span>
            @if (! $hasShell)<span class="badge amber">HASSHELL off</span>@endif
        </span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield', 'cls' => 'hico']) Security</h3>
        <p class="help" style="margin:6px 0 0">Keys <span class="mono">~/.ssh/authorized_keys</span> me jati hain.
            Private key kabhi panel/agent par store nahi hoti — sirf public key yahan daalo.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Authorized Keys — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search keys…" data-filter-rows="#acp-sshkeys tbody tr" aria-label="Search keys">
    </div>
    <div class="table-wrap">
        <table id="acp-sshkeys">
            <thead><tr><th>Type</th><th>Key</th><th>Comment</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($keys as $row)
                <tr>
                    <td><span class="badge blue mono">{{ $row['type'] }}</span></td>
                    <td class="mono">{{ substr($row['key'], 0, 20) }}…</td>
                    <td class="mono">{{ $row['comment'] }}</td>
                    <td class="right">
                        @can('ssh.manage')
                            <form method="post" action="{{ route('ssh.destroy') }}" onsubmit="return confirm('Remove this key?')">
                                @csrf
                                <input type="hidden" name="key_id" value="{{ $row['id'] }}">
                                <button class="btn small danger" type="submit">Remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No authorized keys yet — neeche se import karo.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('ssh.manage')
<div class="grid cols-2 mt">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Import Public Key</h3>
        <p class="help">Ek line: <span class="mono">ssh-ed25519 AAAA… comment</span>. Options / private key fail-closed.</p>
        <form method="post" action="{{ route('ssh.store') }}">
            @csrf
            <label for="pubkey">Public key</label>
            <textarea id="pubkey" name="pubkey" rows="3" required maxlength="9000" placeholder="ssh-ed25519 AAAA… laptop">{{ old('pubkey') }}</textarea>
            <button class="btn mt" type="submit">Authorize Key</button>
        </form>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'terminal', 'cls' => 'hico']) Shell</h3>
        <form method="post" action="{{ route('ssh.shell') }}">
            @csrf
            <label style="display:block; margin-top:8px"><input type="radio" name="shell" value="nologin" @checked($shell === 'nologin')> <span class="mono">nologin</span> (default — sirf SFTP)</label>
            <label style="display:block; margin-top:8px"><input type="radio" name="shell" value="bash" @checked($shell === 'bash') @disabled(! $hasShell)> <span class="mono">/bin/bash</span> @if (! $hasShell)<span class="badge amber">HASSHELL off</span>@endif</label>
            <button class="btn mt" type="submit">Set Shell</button>
        </form>
    </div>
</div>
@endcan
@endif
@endsection
