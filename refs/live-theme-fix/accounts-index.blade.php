@extends('layouts.panel')

@section('title', 'List Accounts')
@section('subtitle', 'Hosting accounts — Linux user, vhost, PHP-FPM, quota')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
    @can('accounts.create')
        <a class="btn small" href="{{ route('accounts.create') }}">+ Create a New Account</a>
    @endcan
@endsection

@section('content')
@php
    $active = $accounts->where('status', 'active')->count();
    $suspended = $accounts->where('status', 'suspended')->count();
    $other = $accounts->count() - $active - $suspended;
@endphp

{{-- stats strip (WHM-style) --}}
<div class="grid cols-3">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Accounts</h3>
        <div class="stat"><span class="num">{{ $accounts->count() }}</span>
            <span class="unit">{{ $liveCount }} live on server</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico']) Active</h3>
        <div class="stat"><span class="num">{{ $active }}</span>
            <span class="unit">{{ $suspended }} suspended @if($other > 0) · {{ $other }} other @endif</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) Plans</h3>
        <p class="help" style="margin:6px 0 0"><a href="{{ route('packages.index') }}">Packages →</a> limits (QUOTA, MAXPOP, MAXSQL…) wahan edit hote hain.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) All Accounts</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search by user, domain, package…"
               data-filter-rows="#acp-accounts tbody tr" aria-label="Search accounts">
    </div>
    <div class="table-wrap">
        <table id="acp-accounts">
            <thead>
            <tr>
                <th>Username</th>
                <th>Domain</th>
                <th>Package</th>
                <th>Quota</th>
                <th>PHP</th>
                <th>Status</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($accounts as $account)
                <tr>
                    <td class="mono">{{ $account->username }}</td>
                    <td><a href="https://{{ $account->main_domain }}" target="_blank" rel="noopener">{{ $account->main_domain }}</a></td>
                    <td class="muted">{{ $account->package?->name ?? '—' }}</td>
                    <td class="muted">{{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</td>
                    <td class="mono">{{ $account->php_version }}</td>
                    <td>
                        <span class="badge {{ $account->status === 'active' ? 'green' : ($account->status === 'suspended' ? 'amber' : ($account->status === 'terminated' ? 'red' : 'blue')) }}">
                            {{ $account->status }}
                        </span>
                    </td>
                    <td class="right">
                        <div class="row" style="justify-content:flex-end">
                            <a class="btn small secondary" href="{{ route('accounts.show', $account) }}">Manage</a>
                            @can('accounts.suspend')
                                @if ($account->status === 'active')
                                    <form method="post" action="{{ route('accounts.suspend', $account) }}" onsubmit="return confirm('Suspend {{ $account->username }}? Site + mail band ho jayenge jab tak unsuspend nahi hota.')">
                                        @csrf
                                        <button class="btn small danger" type="submit">Suspend</button>
                                    </form>
                                @elseif ($account->status === 'suspended')
                                    <form method="post" action="{{ route('accounts.unsuspend', $account) }}">
                                        @csrf
                                        <button class="btn small" type="submit">Unsuspend</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No hosting accounts yet — Create a New Account se shuru karo.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Package upgrade, quota, PHP, terminate — <strong>Manage</strong> kholo. paneld queue sab privileged kaam karta hai (panel kabhi root nahi chalta).</p>
</div>
@endsection
