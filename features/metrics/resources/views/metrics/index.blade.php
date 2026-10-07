@extends('layouts.panel')

@section('title', 'Metrics')
@section('subtitle', 'Visitors / Errors / Bandwidth — access log se')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($stats === null)
<div class="card"><p class="muted">Is login ka koi hosting account nahi hai.</p></div>
@else
<div class="cards">
    <div class="card"><h3>Bandwidth</h3><p class="big">{{ $human }}</p></div>
    <div class="card"><h3>Visitors</h3><p class="big">{{ $stats['visitors'] }}</p></div>
    <div class="card"><h3>Requests</h3><p class="big">{{ $stats['requests'] }}</p></div>
    <div class="card"><h3>Errors (4xx/5xx)</h3><p class="big">{{ $stats['errors'] }}</p></div>
</div>

<div class="card">
    <h3>Top Pages</h3>
    @if (empty($stats['top']))
        <p class="muted">Abhi koi traffic nahi.</p>
    @else
        <table>
            <tr><th>Page</th><th>Hits</th></tr>
            @foreach ($stats['top'] as $path => $hits)
            <tr><td><code>{{ $path }}</code></td><td>{{ $hits }}</td></tr>
            @endforeach
        </table>
    @endif
</div>
@endif
@endsection
