@extends('layouts.panel')

@section('title', 'Node.js Selector')
@section('subtitle', 'Server ka Node runtime — live detect, apps SSH se')

@section('actions')
    <a class="btn small secondary" href="{{ route('php.index') }}">MultiPHP</a>
    <a class="btn small secondary" href="{{ route('apps.index') }}">App Installer</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) Node.js runtime</h3>
        <div class="row mt" style="gap:20px; flex-wrap:wrap">
            <div class="stat"><span class="num">{{ $nodeVersion ?? '—' }}</span><span class="unit">server Node version</span></div>
            <div class="stat"><span class="num">{{ $agentOk ? 'OK' : '—' }}</span><span class="unit">root agent</span></div>
        </div>
        @if ($nodeVersion)
            <p class="help" style="margin:10px 0 0"><span class="badge green">installed</span>
                Node server par live hai — abhi detect kiya gaya (<span class="mono">node -v</span>, root agent se).</p>
        @elseif (! $agentOk)
            <p class="empty" style="margin-top:10px">Root agent offline — Node version detect nahi ho saka.</p>
        @else
            <p class="help" style="margin:10px 0 0"><span class="badge amber">not found</span>
                Node binary nahi mila — server admin se install karwao.</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) App kaise chalayein</h3>
        <p class="help" style="margin:6px 0 0">
            1. <a href="{{ route('ssh.index') }}">SSH Access</a> se login karo ·
            2. App folder me <span class="mono">npm install</span> ·
            3. <span class="mono">node server.js</span> (ya apna entry file) kisi high port par ·
            4. Domain se jodne ke liye admin se proxy/port mapping.
            <br>Per-app process manager (create/start/stop, PM2-style) agent ke agle update me aayega.</p>
    </div>
</div>

@if ($account)
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'folder', 'cls' => 'hico']) Account — {{ $account->username }}</h3>
    <p class="help" style="margin:6px 0 0">App files <span class="mono">/home/{{ $account->username }}/</span> me rakho
        (<span class="mono">public_html</span> ke bahar) — <a href="{{ route('files.index') }}">File Manager</a> ya
        <a href="{{ route('git.index') }}">Git Version Control</a> se deploy karo.</p>
</div>
@endif
@endsection
