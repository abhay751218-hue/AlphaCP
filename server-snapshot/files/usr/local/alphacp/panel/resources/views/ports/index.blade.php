@extends('layouts.panel')

@section('title', 'Ports Control')
@section('subtitle', 'Owner control — kaun sa panel kis port par khule (ek panel = ek port)')

@section('actions')
    <a class="btn small secondary" href="{{ route('audit.index') }}">Audit Log</a>
@endsection

@section('content')
@if (session('success'))
<div class="card" style="border:2px solid #1d8a3a"><p>{{ session('success') }}</p></div>
@endif
@if (session('warning'))
<div class="card" style="border:2px solid #b8860b"><p>{{ session('warning') }}</p></div>
@endif
@if ($errors->any())
<div class="card" style="border:2px solid #a22"><p>{{ $errors->first() }}</p></div>
@endif

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'plug', 'cls' => 'hico']) Abhi live links</h3>
        <div class="table-wrap mt">
            <table>
                <tbody>
                <tr><td><span class="badge red">Server Manager</span> root/reseller</td><td class="mono">https://{{ request()->getHost() }}:{{ $cfg['whm'] }}/</td></tr>
                <tr><td><span class="badge blue">Account Panel</span> customer</td><td class="mono">https://{{ request()->getHost() }}:{{ $cfg['cpanel'] }}/</td></tr>
                <tr><td><span class="badge green">Webmail</span> Roundcube</td><td class="mono">https://{{ request()->getHost() }}:{{ $cfg['webmail'] }}/</td></tr>
                <tr><td><span class="badge gray">Link-page</span></td><td class="mono">@if ($cfg['link_enabled'])https://{{ request()->getHost() }}:{{ $cfg['link'] }}/@else — band hai @endif</td></tr>
                </tbody>
            </table>
        </div>
        @if ($applied !== null)
            <p class="help mt">Agent status: nginx <span class="badge blue">{{ $applied['nginx'] ?? '?' }}</span> ·
                template <span class="badge {{ ($applied['template'] ?? false) ? 'green' : 'amber' }}">{{ ($applied['template'] ?? false) ? 'ready' : 'pending' }}</span></p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'alert', 'cls' => 'hico']) Dhyan rahe</h3>
        <p class="help" style="margin:6px 0 0">Save par DB + <span class="mono">etc/ports.json</span> update hota hai aur
            agent <span class="mono">ports.apply</span> nginx vhosts regen + reload karta hai
            (nginx -t fail = purani vhosts wapas). Naya port <strong>AWS security group + ufw</strong> me kholna
            zaroori hai — warna panel bahar se nahi khulega.</p>
    </div>
</div>

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Port &harr; Panel Mapping</h3>
    <form method="POST" action="{{ route('ports.store') }}">
        @csrf
        <div class="grid cols-2">
            <div>
                <label for="pt-whm">Server Manager port (root / reseller)</label>
                <input id="pt-whm" type="number" name="whm" min="1024" max="65535" value="{{ old('whm', $cfg['whm']) }}" required>
                <label for="pt-cpanel">Account Panel port (customers)</label>
                <input id="pt-cpanel" type="number" name="cpanel" min="1024" max="65535" value="{{ old('cpanel', $cfg['cpanel']) }}" required>
            </div>
            <div>
                <label for="pt-webmail">Webmail port (Roundcube)</label>
                <input id="pt-webmail" type="number" name="webmail" min="1024" max="65535" value="{{ old('webmail', $cfg['webmail']) }}" required>
                <label for="pt-link">Link-page port (static, PHP nahi)</label>
                <input id="pt-link" type="number" name="link" min="1024" max="65535" value="{{ old('link', $cfg['link']) }}" required>
            </div>
        </div>
        <label style="display:flex;gap:8px;align-items:center" class="mt">
            <input type="checkbox" name="link_enabled" value="1" @checked(old('link_enabled', $cfg['link_enabled']))>
            Link-page chalu rakhein (band karne par purana port bilkul band ho jata hai)
        </label>
        <button class="btn mt" type="submit">Save + nginx par apply karo</button>
    </form>
</div>
@endsection
