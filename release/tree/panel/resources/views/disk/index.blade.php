@extends('layouts.panel')

@section('title', 'Disk Usage')
@section('subtitle', 'Folder-wise space — account home only')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('email-disk.index') }}">Email Disk Usage</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else
@php
    $usedMb = $bytes / 1024 / 1024;
    $pct = $quotaMb > 0 ? (int) min(100, round($usedMb / $quotaMb * 100)) : 0;
@endphp

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'disk', 'cls' => 'hico']) This folder</h3>
        <div class="stat"><span class="num">{{ number_format($bytes / 1048576, 2) }}</span><span class="unit">MB in <span class="mono">~/{{ $path }}</span></span></div>
        @if ($quotaMb > 0)
            <p class="mono mt">{{ number_format($usedMb, 2) }} MB / {{ number_format($quotaMb) }} MB
                <span class="badge {{ $pct >= 90 ? 'red' : ($pct >= 70 ? 'amber' : 'green') }}">{{ $pct }}%</span></p>
            <div class="meter"><span style="width: {{ max(3, $pct) }}%"></span></div>
        @elseif ($quotaMb < 0)
            <p class="mt"><span class="badge green">quota unlimited</span></p>
        @endif
        @if ($truncated)
            <p class="help"><span class="badge amber">truncated</span> walk cap (2000 nodes) — kuch folders skip</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'folder', 'cls' => 'hico']) Navigation</h3>
        <p class="mt mono" style="word-break:break-all">
            <a href="{{ route('disk.index') }}">home</a>@if ($path !== '')/{{ $path }}@endif
        </p>
        @if ($path !== '')
            <a class="btn small secondary mt" href="{{ route('disk.index', ['path' => $parent]) }}">&larr; Up one level</a>
        @endif
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'disk', 'cls' => 'hico']) Contents — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-disk tbody tr" aria-label="Search entries">
    </div>
    <div class="table-wrap">
        <table id="acp-disk">
            <thead><tr><th>Name</th><th>Type</th><th class="right">Size</th></tr></thead>
            <tbody>
            @forelse ($entries as $row)
                <tr>
                    <td class="mono">
                        @if (($row['type'] ?? '') === 'dir')
                            @include('partials.icons', ['icon' => 'folder', 'cls' => 'hico'])
                            <a href="{{ route('disk.index', ['path' => trim($path.'/'.$row['name'], '/')]) }}">{{ $row['name'] }}/</a>
                        @else
                            @include('partials.icons', ['icon' => 'file', 'cls' => 'hico'])
                            {{ $row['name'] }}
                        @endif
                    </td>
                    <td><span class="badge {{ ($row['type'] ?? '') === 'dir' ? 'blue' : 'gray' }}">{{ $row['type'] ?? '' }}</span></td>
                    <td class="right mono">{{ number_format(((int) ($row['bytes'] ?? 0)) / 1024, 1) }} KiB</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Usage comes from paneld. Folder sizes show here on a live server.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
