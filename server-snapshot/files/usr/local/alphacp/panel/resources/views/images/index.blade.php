@extends('layouts.panel')

@section('title', 'Images')
@section('subtitle', 'Account ki image files')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Images ({{ count($images) }})</h3>
    @if (empty($images))
        <p class="muted">Koi image file nahi.</p>
    @else
        <table>
            <tr><th>File</th></tr>
            @foreach ($images as $img)
            <tr><td>{{ $img }}</td></tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
