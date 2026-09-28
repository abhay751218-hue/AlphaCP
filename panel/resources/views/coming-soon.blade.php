@extends('layouts.panel', ['serverName' => $serverName ?? null])

@section('title', 'Coming soon')
@section('content')
  <div class="card">
    <h3>Coming soon</h3>
    <p>Ye module apne roadmap step ke saath aayega — poora status <code>docs/09-cpanel-parity-checklist.md</code> me hai.</p>
    <a class="btn" href="{{ route('dashboard') }}">← Dashboard</a>
  </div>
@endsection
