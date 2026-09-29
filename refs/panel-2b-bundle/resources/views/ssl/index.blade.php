@extends('layouts.panel')

@section('title', 'SSL/TLS Status')
@section('subtitle', 'Har domain ka certificate — abhi self-signed (Let\'s Encrypt AutoSSL next)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
    @can('domains.view')
        <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
    @endcan
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>Ye tool <strong>customer cPanel</strong> ka hai. Customer apne domains par SSL issue karega.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">Is login se koi hosting account linked nahi.</p>
</div>
@else
<div class="card">
    <h3>Certificates</h3>
    <p class="help">{{ $account->username }} · {{ $account->main_domain }}</p>
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Domain</th>
                <th>Type</th>
                <th>SSL</th>
                <th>Issuer</th>
                <th>Expires</th>
                <th></th>
            </tr>
            @forelse ($domains as $row)
                <tr>
                    <td class="mono">{{ $row->domain }}</td>
                    <td><span class="badge blue">{{ $row->type }}</span></td>
                    <td><span class="badge {{ ($row->ssl_status ?? 'none') === 'active' ? 'green' : 'amber' }}">{{ $row->ssl_status ?? 'none' }}</span></td>
                    <td class="muted">{{ $row->ssl_issuer ?? '—' }}</td>
                    <td class="muted">{{ $row->ssl_not_after?->toDateString() ?? '—' }}</td>
                    <td class="right">
                        @can('ssl.manage')
                            @if (($row->ssl_status ?? 'none') !== 'active' && $row->type !== 'redirect' && $row->ssl_status !== 'removing')
                                <form method="post" action="{{ route('ssl.issue', $row) }}" style="display:inline">
                                    @csrf
                                    <button class="btn small" type="submit">Issue self-signed</button>
                                </form>
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
                <tr><td colspan="6" class="empty">Pehle domain add karo.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endif
@endsection
