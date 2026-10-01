@extends('layouts.panel')

@section('title', 'Create a New Account')
@section('subtitle', 'Linux user + home + Apache vhost + PHP-FPM pool + quota')

@section('actions')
    <a class="btn small secondary" href="{{ route('accounts.index') }}">← Accounts</a>
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
        <h3>👤 Account details</h3>
        <form method="post" action="{{ route('accounts.store') }}">
            @csrf

            <label for="username">Username (Linux user)</label>
            <input id="username" name="username" value="{{ old('username') }}" required
                   pattern="[a-z][a-z0-9]{2,15}" maxlength="16" autocapitalize="none" spellcheck="false"
                   @disabled(! $gate['ok'])>
            <p class="help">3–16 chars, lowercase, must start with a letter. <span class="mono">root</span>/<span class="mono">admin</span> are not allowed.</p>

            <label for="main_domain">Primary domain</label>
            <input id="main_domain" name="main_domain" value="{{ old('main_domain') }}" required
                   autocapitalize="none" spellcheck="false" @disabled(! $gate['ok'])>
            <p class="help">FQDN, jaise <span class="mono">shop.example.com</span>.</p>

            <label for="contact_email">Contact email</label>
            <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}" required
                   @disabled(! $gate['ok'])>

            <label for="package_id">Package</label>
            <select id="package_id" name="package_id" required @disabled(! $gate['ok'])>
                @foreach ($packages as $package)
                    <option value="{{ $package->id }}" @selected(old('package_id', $package->is_default ? $package->id : null) == $package->id)>
                        {{ $package->name }} ({{ $package->quotaMb() < 0 ? 'unlimited' : $package->quotaMb() . ' MB' }})
                    </option>
                @endforeach
            </select>

            <label for="php_version">PHP</label>
            <select id="php_version" name="php_version" required @disabled(! $gate['ok'])>
                @foreach (\App\Support\PhpVersions::all() as $php)
                    <option value="{{ $php }}" @selected(old('php_version', '8.4') === $php)>{{ $php }}</option>
                @endforeach
            </select>

            <label for="password">Password (optional)</label>
            <input id="password" name="password" type="password" autocomplete="new-password" @disabled(! $gate['ok'])>
            <p class="help">Leave blank to generate a strong password (shown once in a flash message).</p>

            <button class="btn mt" type="submit" @disabled(! $gate['ok'])>Account banao</button>
        </form>
    </div>

    <div class="card">
        <h3>ℹ️ Kya banega</h3>
        <ul class="help" style="margin-left:18px">
            <li>Linux user <span class="mono">/usr/sbin/nologin</span> shell ke saath</li>
            <li>Home: <span class="mono">/home/&lt;user&gt;/public_html</span></li>
            <li>Apache vhost port 80 + PHP-FPM pool (socket)</li>
            <li>Disk quota package se</li>
            <li>Panel login (role: user) — pehle login par password change</li>
            <li>Fail par paneld khud rollback karta hai (user/vhost/pool)</li>
        </ul>
        <p class="help mt">SSL, addon domains, email Step 5/7 me. Packages UI Step 4 me.</p>
    </div>
</div>
@endsection
