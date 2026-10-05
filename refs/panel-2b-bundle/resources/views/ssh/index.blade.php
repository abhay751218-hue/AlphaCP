@extends('layouts.panel')

@section('title', 'SSH Access')
@section('subtitle', 'Authorized keys + nologin/bash — account home only')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers manage SSH keys here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>SSH Access — {{ $account->username }}</h3>
    <p class="help">Keys go in <span class="mono">~/.ssh/authorized_keys</span>. The private key is never stored on the panel or agent.
        Shell: <span class="mono">{{ $shell }}</span>
        @if (! $hasShell)
            · package HASSHELL off — no bash
        @endif
    </p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Type</th>
                <th>Key</th>
                <th>Comment</th>
                <th></th>
            </tr>
            @forelse ($keys as $row)
                <tr>
                    <td class="mono">{{ $row['type'] }}</td>
                    <td class="mono">{{ substr($row['key'], 0, 20) }}…</td>
                    <td class="mono">{{ $row['comment'] }}</td>
                    <td class="right">
                        @can('ssh.manage')
                            <form method="post" action="{{ route('ssh.destroy') }}" onsubmit="return confirm('Remove this key?')">
                                @csrf
                                <input type="hidden" name="key_id" value="{{ $row['id'] }}">
                                <button class="btn small danger" type="submit">remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No authorized keys yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

@can('ssh.manage')
<div class="card mt">
    <h3>Public key import</h3>
    <p class="help">Ek line: <span class="mono">ssh-ed25519 AAAA… comment</span>. Options / private key fail closed.</p>
    <form method="post" action="{{ route('ssh.store') }}">
        @csrf
        <label for="pubkey">Public key</label>
        <textarea id="pubkey" name="pubkey" rows="3" required maxlength="9000" placeholder="ssh-ed25519 AAAA… laptop">{{ old('pubkey') }}</textarea>
        <button class="btn mt" type="submit">Authorize</button>
    </form>
</div>
<div class="card mt">
    <h3>Shell</h3>
    <form method="post" action="{{ route('ssh.shell') }}">
        @csrf
        <label><input type="radio" name="shell" value="nologin" @checked($shell === 'nologin')> nologin (default)</label>
        <label><input type="radio" name="shell" value="bash" @checked($shell === 'bash') @disabled(! $hasShell)> /bin/bash @if (! $hasShell)(HASSHELL off)@endif</label>
        <button class="btn mt" type="submit">Set shell</button>
    </form>
</div>
@endcan
@endif
@endsection
