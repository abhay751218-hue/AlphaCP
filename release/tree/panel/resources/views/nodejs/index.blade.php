@extends('layouts.panel')

@section('title', 'Setup Node.js App')
@section('subtitle', 'PM2-style app manager — create, start/stop/restart, logs')

@section('actions')
    <a class="btn small secondary" href="{{ route('php.index') }}">MultiPHP</a>
    <a class="btn small secondary" href="{{ route('apps.index') }}">App Installer</a>
@endsection

@section('content')
@if (session('success'))
    <div class="card mb"><p class="help" style="margin:0">✅ {{ session('success') }}</p></div>
@endif
@if ($errors->any())
    <div class="card mb"><p class="empty" style="margin:0">⚠️ {{ $errors->first() }}</p></div>
@endif

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'box', 'cls' => 'hico']) Node.js runtime</h3>
        <div class="row mt" style="gap:20px; flex-wrap:wrap">
            <div class="stat"><span class="num">{{ $nodeVersion ?? '—' }}</span><span class="unit">server Node version</span></div>
            <div class="stat"><span class="num">{{ $appsOk ? 'ON' : '—' }}</span><span class="unit">app manager</span></div>
        </div>
        @if ($nodeVersion)
            <p class="help" style="margin:10px 0 0"><span class="badge green">installed</span>
                Live detect (<span class="mono">node -v</span>, root agent se).</p>
        @elseif (! $agentOk)
            <p class="empty" style="margin-top:10px">Root agent offline — Node version detect nahi ho saka.</p>
        @else
            <p class="help" style="margin:10px 0 0"><span class="badge amber">not found</span>
                Node binary nahi mila — d13 installer ise apt se install karta hai.</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Kaise kaam karta hai</h3>
        <p class="help" style="margin:6px 0 0">
            Har app ek managed process hai (PM2-style): crash par <strong>auto-restart</strong>,
            boot par auto-start, output <span class="mono">app.log</span> me. Files
            <span class="mono">~/nodeapps/&lt;app&gt;/</span> me — <a href="{{ route('files.index') }}">File Manager</a>
            ya <a href="{{ route('git.index') }}">Git</a> se deploy karo, dependencies
            <a href="{{ route('ssh.index') }}">SSH</a> se <span class="mono">npm install</span>.</p>
    </div>
</div>

@if ($panelMode === 'whm')
<div class="card mt">
    <p>This tool is part of the <strong>customer account panel</strong> — customers apni Node apps yahan manage karte hain.</p>
</div>
@elseif (! $account)
<div class="card mt">
    <p class="empty">No hosting account is linked to this login.</p>
</div>
@else

@can('software.manage')
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'cog', 'cls' => 'hico']) Setup Node.js App</h3>
    <form method="post" action="{{ route('nodejs.apps.store') }}">
        @csrf
        <div class="row" style="align-items:flex-end; flex-wrap:wrap; gap:12px">
            <div>
                <label for="name">App name</label>
                <input id="name" name="name" required maxlength="16" pattern="[a-z][a-z0-9]{0,15}" placeholder="myapp" style="min-width:140px">
            </div>
            <div>
                <label for="entry">Entry file</label>
                <input id="entry" name="entry" maxlength="35" placeholder="app.js (default)" style="min-width:160px">
            </div>
            <div>
                <label for="port">Port (3000–3999)</label>
                <input id="port" name="port" type="number" min="3000" max="3999" value="3000" required style="min-width:110px">
            </div>
            <button class="btn" type="submit">Create &amp; Start</button>
        </div>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        @error('entry')<p class="error">{{ $message }}</p>@enderror
        @error('port')<p class="error">{{ $message }}</p>@enderror
        <p class="help" style="margin:8px 0 0">Entry file na ho to sample app ban jati hai (127.0.0.1 par HTTP server) — turant test ke liye.</p>
    </form>
</div>
@endcan

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'services', 'cls' => 'hico']) Apps — {{ $account->username }}
        <span class="badge blue">{{ count($apps) }}</span></h3>
    @if (! $appsOk)
        <p class="empty">Agent par node.list task nahi — d13 agent update deploy karo.</p>
    @elseif ($apps === [])
        <p class="empty">Abhi koi app nahi — upar se pehli app banao.</p>
    @else
        <table>
            <thead><tr><th>App</th><th>Entry</th><th>Port</th><th>Status</th><th>PID</th><th>Actions</th></tr></thead>
            <tbody>
            @foreach ($apps as $app)
                @php($st = (string) ($app['state']['active'] ?? 'unknown'))
                <tr>
                    <td class="mono">{{ $app['name'] }}</td>
                    <td class="mono">{{ $app['entry'] }}</td>
                    <td class="mono">{{ $app['port'] ?: '—' }}</td>
                    <td><span class="badge {{ $st === 'active' ? 'green' : ($st === 'failed' ? 'red' : 'amber') }}">{{ $st }}</span></td>
                    <td class="mono">{{ ($app['state']['pid'] ?? 0) ?: '—' }}</td>
                    <td>
                        @can('software.manage')
                        <div class="row" style="gap:6px; flex-wrap:wrap">
                            @foreach (['start', 'stop', 'restart'] as $act)
                            <form method="post" action="{{ route('nodejs.apps.control') }}">
                                @csrf
                                <input type="hidden" name="name" value="{{ $app['name'] }}">
                                <input type="hidden" name="action" value="{{ $act }}">
                                <button class="btn small {{ $act === 'restart' ? '' : 'secondary' }}" type="submit">{{ ucfirst($act) }}</button>
                            </form>
                            @endforeach
                            <form method="post" action="{{ route('nodejs.apps.control') }}">
                                @csrf
                                <input type="hidden" name="name" value="{{ $app['name'] }}">
                                <input type="hidden" name="action" value="remove">
                                <button class="btn small danger" type="submit">Remove</button>
                            </form>
                        </div>
                        @endcan
                    </td>
                </tr>
                @if (($app['log_tail'] ?? '') !== '')
                <tr>
                    <td colspan="6">
                        <details>
                            <summary class="help">app.log (aakhri hissa) — {{ $app['name'] }}</summary>
                            <pre class="mono" style="max-height:220px; overflow:auto; white-space:pre-wrap; margin:8px 0 0">{{ $app['log_tail'] }}</pre>
                        </details>
                    </td>
                </tr>
                @endif
            @endforeach
            </tbody>
        </table>
        <p class="help" style="margin:10px 0 0">Remove sirf process/unit hataata hai — app files <span class="mono">~/nodeapps/</span> me surakshit rehti hain.</p>
    @endif
</div>
@endif
@endsection
