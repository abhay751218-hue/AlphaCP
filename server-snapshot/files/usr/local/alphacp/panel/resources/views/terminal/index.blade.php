@extends('layouts.panel')

@section('title', 'Terminal')
@section('subtitle', 'AlphaCP Terminal — read-only whitelist, command root agent chalata hai')

@section('actions')
    <a class="btn small secondary" href="{{ route('system.services') }}">Service Status</a>
    <a class="btn small secondary" href="{{ route('ssh.index') }}">SSH Access</a>
@endsection

@section('content')
@if ($error)
<div class="card mb"><p class="empty" style="margin:0">⚠️ {{ $error }}</p></div>
@endif

<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'terminal', 'cls' => 'hico']) Command chalao</h3>
        <form method="POST" action="{{ route('terminal.run') }}">
            @csrf
            <div class="row" style="flex-wrap:wrap; align-items:flex-end">
                <div style="flex:1; min-width:220px">
                    <label for="term-cmd">Command</label>
                    <input id="term-cmd" type="text" name="command" placeholder="ls -la /home" value="{{ $cmd }}" maxlength="200" required autocomplete="off" spellcheck="false" class="mono">
                </div>
                <button class="btn" type="submit" @disabled(! $agentOk)>Run</button>
            </div>
        </form>
        @if (! $agentOk)
            <p class="empty" style="margin-top:8px">Root agent offline — commands abhi nahi chalenge.</p>
        @endif
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Quick commands</h3>
        <form method="POST" action="{{ route('terminal.run') }}">
            @csrf
            <div class="row mt" style="gap:6px; flex-wrap:wrap">
                <button class="btn small secondary" type="submit" name="command" value="uptime">uptime</button>
                <button class="btn small secondary" type="submit" name="command" value="df -h">df -h</button>
                <button class="btn small secondary" type="submit" name="command" value="free -m">free -m</button>
                <button class="btn small secondary" type="submit" name="command" value="ls -la /home">ls /home</button>
                <button class="btn small secondary" type="submit" name="command" value="whoami">whoami</button>
                <button class="btn small secondary" type="submit" name="command" value="date">date</button>
                <button class="btn small secondary" type="submit" name="command" value="uname -a">uname -a</button>
                <button class="btn small secondary" type="submit" name="command" value="php -v">php -v</button>
            </div>
        </form>
    </div>
</div>

@if ($cmd)
<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'terminal', 'cls' => 'hico']) <span class="mono">$ {{ $cmd }}</span></h3>
    <pre class="mono" style="white-space:pre-wrap; background:#101418; color:#d7e0ea; padding:14px; border-radius:8px; max-height:420px; overflow:auto; margin-top:10px">{{ $output !== null && $output !== '' ? $output : '(koi output nahi)' }}</pre>
</div>
@endif

@if ($history !== [])
<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'clock', 'cls' => 'hico']) History (is session ki last {{ count($history) }})</h3>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Time</th><th>Command</th><th>Result</th><th>Rerun</th></tr></thead>
            <tbody>
            @foreach ($history as $h)
                <tr>
                    <td class="mono">{{ $h['at'] ?? '' }}</td>
                    <td class="mono">$ {{ $h['cmd'] ?? '' }}</td>
                    <td>
                        @if (($h['error'] ?? null))
                            <span class="badge amber">error</span>
                        @else
                            <span class="badge green">ok</span>
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="{{ route('terminal.run') }}">
                            @csrf
                            <button class="btn small secondary" type="submit" name="command" value="{{ $h['cmd'] ?? '' }}">Run again</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'list', 'cls' => 'hico']) Allowed commands (read-only whitelist)</h3>
    <p class="help" style="margin:6px 0 10px">Security ke liye sirf ye read-only commands chalte hain — agent par
        dobara validate hote hain, chaining (<span class="mono">; | &amp; ` $ &gt; &lt;</span>) blocked hai.</p>
    <div class="row" style="gap:6px; flex-wrap:wrap">
        @foreach ($allowed as $a)
            <span class="badge blue mono">{{ trim($a) }}</span>
        @endforeach
    </div>
</div>
@endsection
