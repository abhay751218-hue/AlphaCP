{{--
    RECONSTRUCTED 2026-10-06 — server par ye file maujood hai (server-snapshot/MANIFEST.txt),
    par alphacp-sync v1.2 ki `-name ssl ... -prune` rule ki wajah se repo snapshot me kabhi
    aayi hi nahi. Repo se panel banane par /ssl 500 deta tha. sync v1.3 ye bug theek karta
    hai; agla `sudo alphacp-sync` server ki asli file yahan la dega. Tab tak ye
    reconstruction SslTest ke contract ko satisfy karti hai.
--}}
@extends('layouts.panel')

@section('title', 'SSL/TLS Status')
@section('subtitle', 'AutoSSL (Let\'s Encrypt) aur self-signed fallback')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Har customer apne domains ka
        SSL yahan se issue/renew karta hai.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>SSL/TLS Status — {{ $account->username }}</h3>
    <p class="help">AutoSSL sirf un domains ke liye certificate maangta hai jinke liye tick laga ho.
        Domain ka DNS isi server par point hona chahiye, warna challenge fail hoga.</p>

    @can('ssl.manage')
    <form method="post" action="{{ route('ssl.autossl') }}" class="stack">
        @csrf
        @error('ssl')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Run AutoSSL</button>
    </form>
    @endcan

    @if ($domains->isEmpty())
        <p class="empty mt">No domains on this account yet.</p>
    @else
        <div class="table-wrap mt">
            <table>
                <thead>
                    <tr>
                        <th>Domain</th><th>Status</th><th>Issuer</th><th>Expires</th>
                        <th>AutoSSL</th><th>Last error</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($domains as $domain)
                    <tr>
                        <td class="mono">{{ $domain->domain }}</td>
                        <td><span class="badge">{{ $domain->ssl_status ?: 'none' }}</span></td>
                        <td class="mono">{{ $domain->ssl_issuer ?: '—' }}</td>
                        <td class="mono">{{ $domain->ssl_not_after ? $domain->ssl_not_after->toDateString() : '—' }}</td>
                        <td>
                            @can('ssl.manage')
                            <form method="post" action="{{ route('ssl.toggle', $domain) }}">
                                @csrf
                                <button class="btn small secondary" type="submit">
                                    {{ $domain->ssl_autossl ? 'Exclude' : 'Include' }}
                                </button>
                            </form>
                            @else
                                <span class="muted">{{ $domain->ssl_autossl ? 'included' : 'excluded' }}</span>
                            @endcan
                        </td>
                        <td class="mono">{{ $domain->ssl_last_error ?: '—' }}</td>
                        <td>
                            @can('ssl.manage')
                            <form method="post" action="{{ route('ssl.issue', $domain) }}" class="row">
                                @csrf
                                <input type="hidden" name="mode" value="letsencrypt">
                                <button class="btn small" type="submit"
                                        title="{{ "Let's Encrypt" }}">{{ "Let's Encrypt" }}</button>
                            </form>
                            <form method="post" action="{{ route('ssl.issue', $domain) }}" class="row">
                                @csrf
                                <input type="hidden" name="mode" value="selfsigned">
                                <button class="btn small secondary" type="submit">self-signed</button>
                            </form>
                            <form method="post" action="{{ route('ssl.destroy', $domain) }}" class="row">
                                @csrf
                                @method('DELETE')
                                <button class="btn small secondary" type="submit"
                                        onclick="return confirm('Remove the installed certificate for {{ $domain->domain }}?');">Remove</button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="help mt">Certificate na aaye to pehle <span class="mono">self-signed</span> laga kar
            site chalao, phir DNS theek hone par <span class="mono">{{ "Let's Encrypt" }}</span> dobara chalao.</p>
    @endif
</div>
@endif
@endsection
