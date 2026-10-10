@extends('layouts.panel')

@section('title', 'Images')
@section('subtitle', 'Account ki image files — thumbnails/scaler ke liye base list')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('disk.index') }}">Disk Usage</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'image', 'cls' => 'hico']) Images</h3>
        <div class="stat"><span class="num">{{ count($images) }}</span><span class="unit">image files found</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'folder', 'cls' => 'hico']) Kahan se aati hain</h3>
        <p class="help" style="margin:6px 0 0">Home folder ki scan — jpg/png/gif/webp files. Upload/rename ke liye
            <a href="{{ route('files.index') }}">File Manager</a> use karo.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'image', 'cls' => 'hico']) Image Files</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(280px,100%)" placeholder="Search images…" data-filter-rows="#acp-images tbody tr" aria-label="Search images">
    </div>
    <div class="table-wrap">
        <table id="acp-images">
            <thead><tr><th>File</th></tr></thead>
            <tbody>
            @forelse ($images as $img)
                <tr><td class="mono">@include('partials.icons', ['icon' => 'image', 'cls' => 'hico']) {{ $img }}</td></tr>
            @empty
                <tr><td class="empty">Koi image file nahi.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
