@extends('layouts.panel')

@section('title', 'Email Disk Usage')
@section('subtitle', 'Maildir folders ka size — kya jagah kha raha hai')

@section('actions')
    <a class="btn small secondary" href="{{ route('email.index') }}">Email Accounts</a>
    <a class="btn small secondary" href="{{ route('disk.index') }}">Disk Usage</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card"><p>This tool is part of the <strong>customer account panel</strong>.</p></div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'disk', 'cls' => 'hico']) Current folder</h3>
        <div class="stat"><span class="num">{{ number_format($bytes / 1048576, 2) }}</span><span class="unit">MB in <span class="mono">/{{ $path }}</span></span></div>
        @if ($truncated)
            <p class="help"><span class="badge amber">truncated</span> Listing badi thi — pehle entries hi dikh rahi hain.</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'folder', 'cls' => 'hico']) Navigation</h3>
        <p class="mt mono" style="word-break:break-all">
            <a href="{{ route('email-disk.index') }}">mail</a>@if ($path !== '')/{{ $path }}@endif
        </p>
        @if ($parent !== null)
            <a class="btn small secondary mt" href="{{ route('email-disk.index', ['path' => $parent]) }}">&larr; Up one level</a>
        @endif
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'disk', 'cls' => 'hico']) Contents — {{ $account->username }}</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-emaildisk tbody tr" aria-label="Search entries">
    </div>
    <div class="table-wrap">
        <table id="acp-emaildisk">
            <thead><tr><th>Name</th><th>Type</th><th class="right">Size</th></tr></thead>
            <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td class="mono">
                        @if ($entry['type'] === 'dir')
                            @include('partials.icons', ['icon' => 'folder', 'cls' => 'hico'])
                            <a href="{{ route('email-disk.index', ['path' => ($path === '' ? '' : $path . '/') . $entry['name']]) }}">{{ $entry['name'] }}</a>
                        @else
                            @include('partials.icons', ['icon' => 'file', 'cls' => 'hico'])
                            {{ $entry['name'] }}
                        @endif
                    </td>
                    <td><span class="badge {{ $entry['type'] === 'dir' ? 'blue' : 'gray' }}">{{ $entry['type'] }}</span></td>
                    <td class="right mono">{{ number_format($entry['bytes'] / 1024, 1) }} KB</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Folder khali hai.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help">Purani mail delete karne ke liye Webmail ya IMAP client use karo — yahan sirf usage dikhta hai.</p>
</div>
@endif
@endsection
