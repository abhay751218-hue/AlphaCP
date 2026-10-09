@extends('layouts.panel')

@section('title', 'Optimize Website')
@section('subtitle', 'Content compression — site ko fast banao')

@section('actions')
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
    <a class="btn small secondary" href="{{ route('php.index') }}">MultiPHP Manager</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'gauge', 'cls' => 'hico']) Current setting</h3>
        <p class="mt"><span class="badge {{ $level === 'disabled' ? 'gray' : 'green' }} mono">{{ $level }}</span></p>
        <p class="help">Compression on hone se pages 60–80% chhote transfer hote hain.</p>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'chart', 'cls' => 'hico']) Modes</h3>
        <p class="help" style="margin:6px 0 0"><strong>Compress all content</strong> = HTML/CSS/JS/JSON sab gzip ·
            <strong>HTML only</strong> = sirf pages · <strong>No compression</strong> = off (debugging ke liye).</p>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'gauge', 'cls' => 'hico']) Compression Setting</h3>
    <form method="POST" action="{{ route('optimize.store') }}">
        @csrf
        <div class="row" style="flex-wrap:wrap; align-items:flex-end">
            <div>
                <label for="opt-level">Mode</label>
                <select id="opt-level" name="level" style="min-width:240px">
                    <option value="disabled" @selected($level === 'disabled')>No compression</option>
                    <option value="all" @selected($level === 'all')>Compress all content</option>
                    <option value="html" @selected($level === 'html')>Compress HTML only</option>
                </select>
            </div>
            <button class="btn" type="submit">Save</button>
        </div>
    </form>
</div>
@endsection
