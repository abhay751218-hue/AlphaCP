@extends('layouts.panel')

@section('title', 'Packages')
@section('subtitle', 'Hosting plans — cPanel-compatible limits (QUOTA, MAXPOP, MAXSQL…)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
    @can('packages.manage')
        <a class="btn small" href="{{ route('packages.create') }}">+ New package</a>
    @endcan
@endsection

@section('content')
<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th>Name</th>
                <th>Quota</th>
                <th>Bandwidth</th>
                <th>Email / FTP / SQL</th>
                <th>Feature list</th>
                <th>Accounts</th>
                <th>Status</th>
                <th></th>
            </tr>
            @forelse ($packages as $package)
                <tr>
                    <td>
                        <span class="mono">{{ $package->name }}</span>
                        @if ($package->is_default) <span class="badge blue">default</span> @endif
                    </td>
                    <td>{{ $package->formatLimit('QUOTA') }} MB</td>
                    <td>{{ $package->formatLimit('BWLIMIT') }}</td>
                    <td class="muted">{{ $package->formatLimit('MAXPOP') }} / {{ $package->formatLimit('MAXFTP') }} / {{ $package->formatLimit('MAXSQL') }}</td>
                    <td class="muted">{{ $package->featureList?->name ?? '—' }}</td>
                    <td>{{ $package->accounts_count }}</td>
                    <td><span class="badge {{ $package->status === 'active' ? 'green' : 'amber' }}">{{ $package->status }}</span></td>
                    <td class="right">
                        @can('packages.manage')
                            <a class="btn small ghost" href="{{ route('packages.edit', $package) }}">edit</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No packages yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card mt">
    <h3>Feature lists</h3>
    <p class="help">Kaun se client tools package me dikhenge. S5+ modules is list ko respect karenge.</p>
    <div class="table-wrap">
        <table>
            <tr><th>Name</th><th>Features</th></tr>
            @foreach ($lists as $list)
                <tr>
                    <td class="mono">{{ $list->name }} @if ($list->is_default)<span class="badge blue">default</span>@endif</td>
                    <td class="muted">{{ implode(', ', array_keys(array_filter((array) $list->features))) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endsection
