@extends('layouts.panel')

@section('title', 'Trash')
@section('subtitle', 'Deleted files — permanent delete karo')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('disk.index') }}">Disk Usage</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'trash', 'cls' => 'hico']) Trash</h3>
        <div class="stat"><span class="num">{{ count($files) }}</span><span class="unit">files in trash</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'alert', 'cls' => 'hico']) Dhyan rahe</h3>
        <p class="help" style="margin:6px 0 0">Yahan se delete = <strong>permanent</strong> — wapas nahi aati.
            Disk space turant free hota hai.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'trash', 'cls' => 'hico']) Trashed Files</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search…" data-filter-rows="#acp-trash tbody tr" aria-label="Search trash">
    </div>
    <div class="table-wrap">
        <table id="acp-trash">
            <thead><tr><th>File</th><th class="right">Actions</th></tr></thead>
            <tbody>
            @forelse ($files as $f)
                <tr>
                    <td class="mono">@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) {{ $f }}</td>
                    <td class="right">
                        <form method="POST" action="{{ route('trash.destroy', $f) }}" onsubmit="return confirm('Permanent delete?')">
                            @csrf @method('DELETE')
                            <button class="btn small danger" type="submit">Delete Forever</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">Trash khaali hai.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
