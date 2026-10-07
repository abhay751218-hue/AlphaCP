@extends('layouts.panel')

@section('title', 'Site Software')
@section('subtitle', 'One-click app installs — cPanel Site Software / WordPress jaisa')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="cards">
    @foreach ($catalog as $app)
    <div class="card">
        <h3>{{ $app['name'] }}</h3>
        <p class="muted">{{ $app['desc'] }}</p>
        @if ($app['id'] === 'wordpress')
            <form method="POST" action="{{ route('apps.store') }}">
                @csrf
                <input type="hidden" name="app" value="wordpress">
                <button class="btn" type="submit">Install WordPress</button>
            </form>
        @else
            <button class="btn secondary" disabled>Jald</button>
        @endif
    </div>
    @endforeach
</div>
@endsection
