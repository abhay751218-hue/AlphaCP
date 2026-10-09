@extends('layouts.panel')

@section('title', 'Service Status & Restart')
@section('subtitle', 'WHM Restart Services — asli systemd state, one-click restart (root agent se)')

@section('actions')
    <a class="btn small secondary" href="{{ route('system.index') }}">System Information</a>
    <a class="btn small secondary" href="{{ route('system.tasks') }}">Task Queue</a>
@endsection

@section('content')
@if (session('success'))
    <div class="card mb"><p class="help" style="margin:0">✅ {{ session('success') }}</p></div>
@endif
@if ($errors->any())
    <div class="card mb"><p class="empty" style="margin:0">⚠️ {{ $errors->first() }}</p></div>
@endif

@php
    $activeCount = collect($services)->where('active', 'active')->count();
@endphp
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'services', 'cls' => 'hico']) Hosting stack</h3>
        <div class="row mt" style="gap:20px; flex-wrap:wrap">
            <div class="stat"><span class="num">{{ $activeCount }}/{{ count($services) }}</span><span class="unit">services active</span></div>
            <div class="stat"><span class="num">{{ $canRestart ? 'ON' : '—' }}</span><span class="unit">restart support (agent)</span></div>
        </div>
        @if (! $canRestart)
            <p class="help" style="margin:10px 0 0"><span class="badge amber">agent old</span>
                service.restart task agent registry me nahi — paneld update ke baad restart buttons kaam karenge.</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Restart kaise kaam karta hai</h3>
        <p class="help" style="margin:6px 0 0">Button dabate hi root agent <span class="mono">systemctl restart</span>
            chalata hai — <strong>sirf allowlisted services</strong> par, aur restart ke baad state dobara check hota hai.
            Har restart <a href="{{ route('audit.index') }}">Audit Log</a> me darj hota hai.
            <span class="mono">paneld</span> (agent khud) list me nahi — wo SSH se restart hota hai.</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Services ({{ count($services) }})</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(260px,100%)" placeholder="Filter services…" data-filter-rows="#acp-services tbody tr" aria-label="Filter services">
    </div>
    @if ($services === [])
        <p class="empty">Agent se service state nahi aaya — root agent offline ho sakta hai.</p>
    @else
    <div class="table-wrap">
        <table id="acp-services">
            <thead><tr><th>Service</th><th>State</th><th>Boot</th><th>Action</th></tr></thead>
            <tbody>
            @foreach ($services as $name => $state)
                <tr>
                    <td class="mono">{{ $name }}</td>
                    <td><span class="badge {{ ($state['active'] ?? '') === 'active' ? 'green' : (($state['active'] ?? '') === 'inactive' ? 'amber' : 'red') }}">{{ $state['active'] ?? '?' }}</span></td>
                    <td class="muted">{{ $state['enabled'] ?? '?' }}</td>
                    <td>
                        @can('system.manage')
                            @if ($canRestart && in_array($name, $restartable, true))
                                <form method="post" action="{{ route('system.services.restart') }}">
                                    @csrf
                                    <input type="hidden" name="service" value="{{ $name }}">
                                    <button class="btn small secondary" type="submit">↻ Restart</button>
                                </form>
                            @else
                                <span class="muted">—</span>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
