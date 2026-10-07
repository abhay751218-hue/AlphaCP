@extends('layouts.panel')

@section('title', 'Hotlink Protection')
@section('subtitle', 'Apni images/videos ko doosri sites par embed hone se bachao')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Hotlink Protection</h3>
    <form method="POST" action="{{ route('secextra.store') }}">
        @csrf
        <input type="hidden" name="kind" value="hotlink">
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="enabled" value="1" @checked($s['enabled'])> Enabled
        </label>
        <label>Allowed domains (ek per line)
            <textarea name="allowed" rows="4" placeholder="example.com&#10;cdn.example.com">{{ implode("\n", $s['allowed']) }}</textarea>
        </label>
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="allow_direct" value="1" @checked($s['allow_direct'])> Direct URL access allow karo
        </label>
        <button class="btn" type="submit">Save</button>
    </form>
</div>
@endsection
