@extends('layouts.panel')

@section('title', 'SSL/TLS Status')
@section('subtitle', "AutoSSL — Let's Encrypt (HTTP-01) · self-signed fallback")

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
    @can('domains.view')
        <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
    @endcan
    @if ($account && $panelMode !== 'whm')
        @can('ssl.manage')
            <form method="post" action="{{ route('ssl.autossl') }}" style="display:inline">
                @csrf
                <button class="btn small" type="submit">Run AutoSSL</button>
            </form>
        @endcan
    @endif
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer AutoSSL (Let's Encrypt) apne domains par chalayega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>Certificates</h3>
    <p class="help">{{ $account->username }} · {{ $account->main_domain }} · DNS is server pe point hona chahiye (HTTP-01).</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>Type</th>
                <th>SSL</th>
                <th>Issuer</th>
                <th>Expires</th>
                <th>AutoSSL</th>
                <th></th>
            </tr>
            @forelse ($domains as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td><span class="badge blue">{{ $row->type }}</span></td>
                    <td>
                        <span class="badge {{ ($row->ssl_status ?? 'none') === 'active' ? 'green' : ((($row->ssl_status ?? '') === 'failed') ? 'red' : 'amber') }}">{{ $row->ssl_status ?? 'none' }}</span>
                        @if ($row->ssl_last_error)
                            <div class="muted" style="max-width:18rem">{{ $row->ssl_last_error }}</div>
                        @endif
                    </td>
                    <td class="muted">{{ $row->ssl_issuer ?? '—' }}</td>
                    <td class="muted">{{ $row->ssl_not_after?->toDateString() ?? '—' }}</td>
                    <td>
                        @can('ssl.manage')
                            <form method="post" action="{{ route('ssl.toggle', $row) }}" style="display:inline">
                                @csrf
                                <button class="btn small secondary" type="submit">{{ $row->ssl_autossl ? 'include' : 'exclude' }}</button>
                            </form>
                        @else
                            <span class="muted">{{ $row->ssl_autossl ? 'include' : 'exclude' }}</span>
                        @endcan
                    </td>
                    <td class="right">
                        @can('ssl.manage')
                            @if ($row->type !== 'redirect' && ! in_array($row->ssl_status, ['pending', 'removing'], true))
                                <form method="post" action="{{ route('ssl.issue', $row) }}" style="display:inline">
                                    @csrf
                                    <input type="hidden" name="mode" value="letsencrypt">
                                    <button class="btn small" type="submit">Let's Encrypt</button>
                                </form>
                                @if (($row->ssl_status ?? 'none') !== 'active')
                                    <form method="post" action="{{ route('ssl.issue', $row) }}" style="display:inline">
                                        @csrf
                                        <input type="hidden" name="mode" value="selfsigned">
                                        <button class="btn small secondary" type="submit">self-signed</button>
                                    </form>
                                @endif
                            @endif
                            @if (($row->ssl_status ?? 'none') === 'active')
                                <form method="post" action="{{ route('ssl.destroy', $row) }}" style="display:inline" onsubmit="return confirm('SSL vhost hataayein?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn small danger" type="submit">remove</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Pehle domain add karo.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endif
@endsection
