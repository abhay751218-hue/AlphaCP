@extends('layouts.panel')

@section('title', 'Optimize Website')
@section('subtitle', 'Content compression — site ko fast banao')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Compression setting</h3>
    <form method="POST" action="{{ route('optimize.store') }}">
        @csrf
        <label>Mode
            <select name="level">
                <option value="disabled" @selected($level === 'disabled')>No compression</option>
                <option value="all" @selected($level === 'all')>Compress all content</option>
                <option value="html" @selected($level === 'html')>Compress HTML only</option>
            </select>
        </label>
        <button class="btn" type="submit">Save</button>
    </form>
</div>
@endsection
