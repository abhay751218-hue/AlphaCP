@extends('layouts.panel')

@section('title', 'Resource Usage')
@section('subtitle', 'Server monitoring — disk / memory / load / CPU (Server Manager jaisa)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="cards">
    <div class="card"><h3>Memory</h3><p class="big">{{ $memory['pct'] }}%</p><p class="muted">{{ $memory['used_mb'] }} / {{ $memory['total_mb'] }} MB</p></div>
    <div class="card"><h3>Disk</h3><p class="big">{{ $disk['pct'] }}%</p><p class="muted">{{ $disk['used_gb'] }} / {{ $disk['total_gb'] }} GB</p></div>
    <div class="card"><h3>Load (1/5/15)</h3><p class="big">{{ $load[1] }}</p><p class="muted">{{ $load[5] }} / {{ $load[15] }}</p></div>
    <div class="card"><h3>CPU cores</h3><p class="big">{{ $cpus }}</p></div>
</div>
@endsection
