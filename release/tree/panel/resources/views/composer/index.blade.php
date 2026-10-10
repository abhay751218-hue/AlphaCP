@extends('layouts.panel')

@section('title', 'PHP Composer')
@section('subtitle', 'Account ki composer.json — asli dependencies, live padhi gayi')

@section('actions')
    <a class="btn small secondary" href="{{ route('php.index') }}">MultiPHP</a>
    <a class="btn small secondary" href="{{ route('php.ini') }}">INI Editor</a>
@endsection

@section('content')
@if ($panelMode === 'whm')
<div class="card">
    <h3>@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) PHP Composer</h3>
    <p class="help" style="margin:6px 0 0">Ye tool <strong>customer account panel</strong> ka hissa hai —
        customer apni composer.json yahan dekhta hai.</p>
</div>
@elseif (! $account)
<div class="card"><p class="empty">No hosting account is linked to this login.</p></div>
@else

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) Composer — {{ $account->username }}</h3>
        <div class="row mt" style="gap:20px; flex-wrap:wrap">
            <div class="stat"><span class="num">{{ count($packages) }}</span><span class="unit">require packages</span></div>
            <div class="stat"><span class="num">{{ count($devPackages) }}</span><span class="unit">dev packages</span></div>
            <div class="stat"><span class="num">{{ $account->php_version ?? '—' }}</span><span class="unit">account PHP</span></div>
        </div>
        @if ($found)
            <p class="help" style="margin:10px 0 0">File: <span class="mono">{{ $found }}</span> — abhi live padhi gayi (root agent, read-only).</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'terminal', 'cls' => 'hico']) Install/update kaise</h3>
        <p class="help" style="margin:6px 0 0"><a href="{{ route('ssh.index') }}">SSH Access</a> se login karke project folder me:
            <span class="mono">composer install</span> (pehli baar) ya <span class="mono">composer update</span>.
            PHP version <a href="{{ route('php.index') }}">MultiPHP Manager</a> se switch hota hai,
            memory/limits <a href="{{ route('php.ini') }}">INI Editor</a> se.</p>
    </div>
</div>

@if ($parseError)
<div class="card mt"><p class="empty">⚠️ {{ $parseError }}</p></div>
@elseif (! $agentOk)
<div class="card mt"><p class="empty">Root agent offline — composer.json abhi nahi padhi ja sakti.</p></div>
@elseif ($found === null)
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'file', 'cls' => 'hico']) composer.json nahi mili</h3>
    <p class="help" style="margin:6px 0 0"><span class="mono">~/public_html/composer.json</span> ya
        <span class="mono">~/composer.json</span> — dono jagah dekha. Composer project deploy karo
        (<a href="{{ route('git.index') }}">Git</a> / <a href="{{ route('files.index') }}">File Manager</a>) phir ye page
        dependencies dikhayega.</p>
</div>
@endif

@if ($packages !== [])
<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'list', 'cls' => 'hico']) require ({{ count($packages) }})</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(260px,100%)" placeholder="Filter packages…" data-filter-rows="#acp-composer tbody tr" aria-label="Filter packages">
    </div>
    <div class="table-wrap">
        <table id="acp-composer">
            <thead><tr><th>Package</th><th>Constraint</th></tr></thead>
            <tbody>
            @foreach ($packages as $pkg)
                <tr>
                    <td class="mono">{{ $pkg['name'] }}</td>
                    <td class="mono">{{ $pkg['constraint'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@if ($devPackages !== [])
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'wrench', 'cls' => 'hico']) require-dev ({{ count($devPackages) }})</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Package</th><th>Constraint</th></tr></thead>
            <tbody>
            @foreach ($devPackages as $pkg)
                <tr>
                    <td class="mono">{{ $pkg['name'] }}</td>
                    <td class="mono">{{ $pkg['constraint'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endif
@endsection
