@extends('layouts.panel')

@section('title', 'Packages')
@section('subtitle', 'Hosting plans — cPanel-compatible limit keys (QUOTA, MAXPOP, MAXSQL…)')

@section('actions')
    <a class="btn small secondary" href="{{ route('accounts.index') }}">List Accounts</a>
    @can('packages.manage')
        <a class="btn small" href="{{ route('packages.create') }}">+ Add a Package</a>
    @endcan
@endsection

@section('content')

{{-- stats strip --}}
<div class="grid cols-3">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) Packages</h3>
        <div class="stat"><span class="num">{{ $packages->count() }}</span>
            <span class="unit">{{ $packages->where('status', 'active')->count() }} active</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'users', 'cls' => 'hico']) Accounts on plans</h3>
        <div class="stat"><span class="num">{{ $packages->sum('accounts_count') }}</span>
            <span class="unit">across all packages</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'list', 'cls' => 'hico']) Feature lists</h3>
        <div class="stat"><span class="num">{{ $lists->count() }}</span>
            <span class="unit">tool visibility sets</span></div>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) All Packages</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(300px,100%)" placeholder="Search packages…"
               data-filter-rows="#acp-packages tbody tr" aria-label="Search packages">
    </div>
    <div class="table-wrap">
        <table id="acp-packages">
            <thead>
            <tr>
                <th>Name</th>
                <th>Disk</th>
                <th>Bandwidth</th>
                <th>Mail / FTP / SQL</th>
                <th>Addon / Sub</th>
                <th>Feature list</th>
                <th>Accounts</th>
                <th>Status</th>
                <th class="right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($packages as $package)
                <tr>
                    <td>
                        <span class="mono">{{ $package->name }}</span>
                        @if ($package->is_default) <span class="badge blue">default</span> @endif
                    </td>
                    <td>{{ $package->formatLimit('QUOTA') }} MB</td>
                    <td class="muted">{{ $package->formatLimit('BWLIMIT') }}</td>
                    <td class="muted">{{ $package->formatLimit('MAXPOP') }} / {{ $package->formatLimit('MAXFTP') }} / {{ $package->formatLimit('MAXSQL') }}</td>
                    <td class="muted">{{ $package->formatLimit('MAXADDON') }} / {{ $package->formatLimit('MAXSUB') }}</td>
                    <td class="muted">{{ $package->featureList?->name ?? '—' }}</td>
                    <td>{{ $package->accounts_count }}</td>
                    <td><span class="badge {{ $package->status === 'active' ? 'green' : 'amber' }}">{{ $package->status }}</span></td>
                    <td class="right">
                        @can('packages.manage')
                            <a class="btn small secondary" href="{{ route('packages.edit', $package) }}">Edit</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">No packages yet — "+ Add a Package" se pehla plan banao.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help"><span class="mono">-1</span> = unlimited. Naye accounts inhi limits ke saath bante hain; existing account ka plan <span class="mono">List Accounts → Manage → upgrade</span> se badlo.</p>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'list', 'cls' => 'hico']) Feature Lists</h3>
    <p class="help">Kaun se client tools package me dikhenge — feature list package se attach hoti hai.</p>
    <div class="table-wrap">
        <table>
            <thead>
            <tr><th>Name</th><th>Enabled features</th></tr>
            </thead>
            <tbody>
            @foreach ($lists as $list)
                <tr>
                    <td class="mono">{{ $list->name }} @if ($list->is_default)<span class="badge blue">default</span>@endif</td>
                    <td>
                        @foreach (array_keys(array_filter((array) $list->features)) as $feat)
                            <span class="badge green">{{ $feat }}</span>
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
