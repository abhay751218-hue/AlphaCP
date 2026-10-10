@extends('layouts.panel')

@section('title', 'Git Status')
@section('subtitle', 'Repo: ' . $dir)

@section('actions')
    <a class="btn small secondary" href="{{ route('git.index') }}">← Git</a>
@endsection

@section('content')
<div class="card">
    <h3>git status --porcelain</h3>
    <pre style="white-space:pre-wrap;background:#111;padding:12px;border-radius:6px">{{ $output ?: '(clean — koi change nahi)' }}</pre>
</div>
@endsection
