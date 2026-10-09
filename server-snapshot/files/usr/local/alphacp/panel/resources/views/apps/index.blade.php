@extends('layouts.panel')

@section('title', 'Site Software')
@section('subtitle', 'One-click app installs — WordPress aur zyada')

@section('actions')
    <a class="btn small secondary" href="{{ route('mysql.index') }}">MySQL Databases</a>
    <a class="btn small secondary" href="{{ route('files.index') }}">File Manager</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) App catalog</h3>
        <div class="stat"><span class="num">{{ count($catalog) }}</span><span class="unit">apps available</span></div>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'services', 'cls' => 'hico']) Kaise kaam karta hai</h3>
        <p class="help" style="margin:6px 0 0">Install dabao — panel database banata hai, files copy karta hai aur
            config likh deta hai. Site turant ready.</p>
    </div>
</div>

<div class="apps-grid mt">
    @foreach ($catalog as $app)
        <div class="atile">
            @include('partials.icons', ['icon' => 'box', 'cls' => 'atico'])
            <strong>{{ $app['name'] }}</strong>
            <p class="help">{{ $app['desc'] }}</p>
            @if ($app['id'] === 'wordpress')
                <form method="POST" action="{{ route('apps.store') }}">
                    @csrf
                    <input type="hidden" name="app" value="wordpress">
                    <button class="btn small" type="submit">Install</button>
                </form>
            @else
                <span class="badge gray">jald aayega</span>
            @endif
        </div>
    @endforeach
</div>
@endsection
