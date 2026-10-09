@extends('layouts.panel')

@section('title', 'Ports Control')
@section('subtitle', 'Owner control — kaun sa panel kis port par khule (ek panel = ek port)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if (session('success'))
<div class="card" style="border:2px solid #2a2"><p>{{ session('success') }}</p></div>
@endif
@if (session('warning'))
<div class="card" style="border:2px solid #a2"><p>{{ session('warning') }}</p></div>
@endif
@if ($errors->any())
<div class="card" style="border:2px solid #a22"><p>{{ $errors->first() }}</p></div>
@endif

<div class="card">
    <h3>Abhi live links</h3>
    <p>
        <strong>WHM (root/reseller):</strong> https://{{ request()->getHost() }}:{{ $cfg['whm'] }}/<br>
        <strong>cPanel (customer):</strong> https://{{ request()->getHost() }}:{{ $cfg['cpanel'] }}/<br>
        <strong>Webmail (Roundcube):</strong> https://{{ request()->getHost() }}:{{ $cfg['webmail'] }}/<br>
        <strong>Link-page:</strong>
        @if ($cfg['link_enabled'])
            https://{{ request()->getHost() }}:{{ $cfg['link'] }}/
        @else
            — band hai
        @endif
    </p>
    @if ($applied !== null)
        <p class="sub">Agent status: nginx {{ $applied['nginx'] ?? '?' }} · template {{ ($applied['template'] ?? false) ? 'ready' : 'pending' }}</p>
    @endif
</div>

<div class="card">
    <h3>Port ↔ panel mapping</h3>
    <form method="POST" action="{{ route('ports.store') }}">
        @csrf
        <label>WHM port (root / reseller)
            <input type="number" name="whm" min="1024" max="65535" value="{{ old('whm', $cfg['whm']) }}" required>
        </label>
        <label>cPanel port (customers)
            <input type="number" name="cpanel" min="1024" max="65535" value="{{ old('cpanel', $cfg['cpanel']) }}" required>
        </label>
        <label>Webmail port (Roundcube)
            <input type="number" name="webmail" min="1024" max="65535" value="{{ old('webmail', $cfg['webmail']) }}" required>
        </label>
        <label>Link-page port (static directory page, PHP nahi)
            <input type="number" name="link" min="1024" max="65535" value="{{ old('link', $cfg['link']) }}" required>
        </label>
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="link_enabled" value="1" @checked(old('link_enabled', $cfg['link_enabled']))>
            Link-page chalu rakhein (band karne par purana port bilkul band ho jata hai)
        </label>
        <button class="btn mt" type="submit">Save + nginx par apply karo</button>
    </form>
    <p class="help mt">Save par DB + etc/ports.json update hota hai aur agent `ports.apply` nginx vhosts
       regen + reload karta hai (nginx -t fail = purani vhosts wapas). Naye port ko AWS security group +
       ufw me kholna zaroori hai — warn panel bahar se nahi khulega.</p>
</div>
@endsection

