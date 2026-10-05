@extends('layouts.panel')

@section('title', 'Disk Usage')
@section('subtitle', 'Folder-wise space — account home only')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers view disk usage here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Disk Usage — {{ $account->username }}</h3>
    @php
        $usedMb = $bytes / 1024 / 1024;
        $pct = $quotaMb > 0 ? (int) min(100, round($usedMb / $quotaMb * 100)) : 0;
    @endphp
    <p class="help">Path: <span class="mono">~/{{ $path }}</span>
        @if ($quotaMb > 0)
            · quota {{ number_format($quotaMb) }} MB
        @elseif ($quotaMb < 0)
            · quota unlimited
        @endif
        @if ($truncated)
            · walk cap (2000 nodes) — kuch folders skip
        @endif
    </p>
    <p class="mono">This folder: {{ number_format($bytes / 1024, 1) }} KiB
        @if ($quotaMb > 0)
            · {{ number_format($usedMb, 2) }} MB / {{ number_format($quotaMb) }} MB
        @endif
    </p>
    @if ($quotaMb > 0)
        <div class="meter"><span style="width: {{ max(3, $pct) }}%"></span></div>
    @endif
    @if ($path !== '')
        <p><a href="{{ route('disk.index', ['path' => $parent]) }}">↑ parent</a></p>
    @endif
    <div class="table-wrap mt">
        <table>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Size</th>
            </tr>
            @forelse ($entries as $row)
                <tr>
                    <td class="mono">
                        @if (($row['type'] ?? '') === 'dir')
                            <a href="{{ route('disk.index', ['path' => trim($path.'/'.$row['name'], '/')]) }}">{{ $row['name'] }}/</a>
                        @else
                            {{ $row['name'] }}
                        @endif
                    </td>
                    <td>{{ $row['type'] ?? '' }}</td>
                    <td class="mono">{{ number_format(((int) ($row['bytes'] ?? 0)) / 1024, 1) }} KiB</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Usage comes from paneld. Folder sizes show here on a live server.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endif
@endsection
