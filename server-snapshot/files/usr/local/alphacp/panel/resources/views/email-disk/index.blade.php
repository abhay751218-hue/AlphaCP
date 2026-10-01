@extends('layouts.panel')

@section('title', 'Email Disk Usage')
@section('subtitle', 'Per-folder mail space — ~/mail only, no purge, no symlink')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <p>This tool is part of the <strong>customer cPanel</strong>. Customers view email disk usage here.</p>
</div>
@elseif (! $account)
<div class="card">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else
<div class="card">
    <h3>Email Disk Usage — {{ $account->username }}</h3>
    <p class="help">Path: <span class="mono">~/mail/{{ $path }}</span>
        @if ($truncated)
            · walk cap (2000 nodes) — kuch folders skip
        @endif
        · Purge later. Pipe/.. fail closed.
    </p>
    <p class="mono">This folder: {{ number_format($bytes / 1024, 1) }} KiB</p>
    @if ($path !== '')
        <p><a href="{{ route('email-disk.index', ['path' => $parent]) }}">↑ parent</a></p>
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
                            <a href="{{ route('email-disk.index', ['path' => trim($path.'/'.$row['name'], '/')]) }}">{{ $row['name'] }}/</a>
                        @else
                            {{ $row['name'] }}
                        @endif
                    </td>
                    <td>{{ $row['type'] ?? '' }}</td>
                    <td class="mono">{{ number_format(((int) ($row['bytes'] ?? 0)) / 1024, 1) }} KiB</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Usage paneld se aati hai. Live server par ~/mail folder sizes yahan dikhenge.</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endif
@endsection
