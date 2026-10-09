@extends('layouts.panel')

@section('title', 'Create a New Account')
@section('subtitle', 'Linux user + home + Apache vhost + PHP-FPM pool + quota')

@section('actions')
    <a class="btn small secondary" href="{{ route('accounts.index') }}">← List Accounts</a>
@endsection

@section('content')
@if (! $gate['ok'])
    <div class="flash warning">
        @if ($gate['reason'] === 'cap')
            License max_accounts limit reached. New accounts will not be created — existing sites keep running.
        @else
            License/trial blocked new accounts. Customer websites/email will not be disabled.
        @endif
    </div>
@endif

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'user', 'cls' => 'hico']) Domain Information</h3>
        <form method="post" action="{{ route('accounts.store') }}">
            @csrf

            <label for="main_domain">Domain</label>
            <input id="main_domain" name="main_domain" value="{{ old('main_domain') }}" required
                   autocapitalize="none" spellcheck="false" placeholder="example.com"
                   data-autofill-username="#username" @disabled(! $gate['ok'])>
            <p class="help">FQDN — username neeche auto-suggest hoga (WHM jaisa).</p>

            <label for="username">Username</label>
            <input id="username" name="username" value="{{ old('username') }}" required
                   pattern="[a-z][a-z0-9]{2,15}" maxlength="16" autocapitalize="none" spellcheck="false"
                   @disabled(! $gate['ok'])>
            <p class="help">3–16 chars, lowercase, letter se start. <span class="mono">root</span>/<span class="mono">admin</span> reserved.</p>

            <label for="password">Password <span class="muted">(blank = strong random, shown once)</span></label>
            <div class="row" data-pw>
                <input id="password" name="password" type="password" autocomplete="new-password"
                       style="flex:1; min-width:180px" @disabled(! $gate['ok'])>
                <button class="btn small secondary" type="button" data-pw-show>Show</button>
                <button class="btn small secondary" type="button" data-pw-gen>Generate</button>
            </div>
            <div class="pw-meter" aria-hidden="true"><span></span><span></span><span></span></div>

            <label for="contact_email">Contact Email</label>
            <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}" required
                   placeholder="owner@example.com" @disabled(! $gate['ok'])>

            <h3 class="mt">@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) Package</h3>
            <label for="package_id">Choose a package</label>
            <select id="package_id" name="package_id" required @disabled(! $gate['ok'])>
                @foreach ($packages as $package)
                    <option value="{{ $package->id }}" @selected(old('package_id', $package->is_default ? $package->id : null) == $package->id)>
                        {{ $package->name }} — {{ $package->quotaMb() < 0 ? 'unlimited' : $package->quotaMb() . ' MB' }} disk,
                        {{ $package->formatLimit('MAXPOP') }} mail, {{ $package->formatLimit('MAXSQL') }} DB,
                        {{ $package->formatLimit('MAXADDON') }} addon
                    </option>
                @endforeach
            </select>
            <p class="help">Limits edit karne ke liye <a href="{{ route('packages.index') }}">Packages</a> page.</p>

            <label for="php_version">PHP version</label>
            <select id="php_version" name="php_version" required @disabled(! $gate['ok'])>
                @foreach (\App\Support\PhpVersions::all() as $php)
                    <option value="{{ $php }}" @selected(old('php_version', '8.4') === $php)>PHP {{ $php }}</option>
                @endforeach
            </select>

            <button class="btn mt" type="submit" @disabled(! $gate['ok'])>+ Create Account</button>
        </form>
    </div>

    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'server', 'cls' => 'hico']) Kya provision hoga</h3>
        <ul class="help" style="margin-left:18px">
            <li>Linux user <span class="mono">/usr/sbin/nologin</span> shell ke saath</li>
            <li>Home: <span class="mono">/home/&lt;user&gt;/public_html</span></li>
            <li>Apache vhost + PHP-FPM pool (socket)</li>
            <li>Disk quota package se, DNS zone seed</li>
            <li>Panel login (role: user) — first login par password change force</li>
            <li>Fail par paneld khud rollback karta hai (user/vhost/pool)</li>
        </ul>
        <h3 class="mt">@include('partials.icons', ['icon' => 'key', 'cls' => 'hico']) Password policy</h3>
        <p class="help">Blank chhoda to 20-char strong password generate hota hai aur create ke baad flash me
            <strong>sirf ek baar</strong> dikhta hai — copy karke customer ko do. Customer pehle login par apna password set karega.</p>
        <h3 class="mt">@include('partials.icons', ['icon' => 'mail', 'cls' => 'hico']) Baad me kya</h3>
        <p class="help">Account banne ke baad: <span class="mono">List Accounts → open</span> se package upgrade,
            quota, PHP version, suspend/terminate. Email/DB/domains customer apne cPanel-style panel (2083) me banayega.</p>
    </div>
</div>
@endsection
