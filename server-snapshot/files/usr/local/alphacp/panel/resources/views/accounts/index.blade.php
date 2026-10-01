@extends('layouts.panel')

@section('title', 'Accounts')
@section('subtitle', 'Hosting accounts — Linux user, vhost, PHP-FPM, quota')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
    @can('accounts.create')
        <a class="btn small" href="{{ route('accounts.create') }}">+ New account</a>
    @endcan
@endsection

@section('content')
<div class="card">
    <p class="hint mb">Live accounts: <strong>{{ $liveCount }}</strong> · paneld queue creates/suspends/terminates (the panel never runs as root).</p>
    <div class="table-wrap">
        <table>
            <tr>
                <th>Username</th>
                <th>Domain</th>
                <th>Package</th>
                <th>Quota</th>
                <th>Status</th>
                <th>PHP</th>
                <th></th>
            </tr>
            @forelse ($accounts as $account)
                <tr>
                    <td class="mono">{{ $account->username }}</td>
                    <td>{{ $account->main_domain }}</td>
                    <td class="muted">{{ $account->package?->name ?? '—' }}</td>
                    <td class="muted">{{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</td>
                    <td>
                        <span class="badge {{ $account->status === 'active' ? 'green' : ($account->status === 'suspended' ? 'amber' : ($account->status === 'terminated' ? 'red' : 'blue')) }}">
                            {{ $account->status }}
                        </span>
                    </td>
                    <td class="mono">{{ $account->php_version }}</td>
                    <td class="right"><a class="btn small ghost" href="{{ route('accounts.show', $account) }}">open</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No hosting accounts yet. Start with Create a New Account.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
