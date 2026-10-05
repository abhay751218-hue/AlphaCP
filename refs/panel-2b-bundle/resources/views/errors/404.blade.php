@extends('layouts.panel')

@section('title', '404 — Page not found')
@section('subtitle', 'This URL is not in the panel')

@section('content')
<div class="card">
    <h3>🔍 404</h3>
    <p class="help">Ho sakta hai ye feature kisi aane wale step me aayega. Dashboard par tiles dekh lo ki kaunsa
        tool kis step me live hoga.</p>
    <p class="mt"><a class="btn" href="{{ url('/dashboard') }}">← Dashboard</a></p>
</div>
@endsection
