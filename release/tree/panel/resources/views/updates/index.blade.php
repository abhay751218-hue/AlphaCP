@extends('layouts.panel')

@section('title', 'Updates')
@section('subtitle', 'Component versions + ab tak install hue update waves — sab asli data')

@section('actions')
    <a class="btn small secondary" href="{{ route('system.index') }}">System Information</a>
    <a class="btn small secondary" href="{{ route('license.index') }}">License</a>
@endsection

@section('content')
<div class="grid cols-2">
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'refresh', 'cls' => 'hico']) Current versions</h3>
        <dl class="kv">
            <dt>AlphaCP panel</dt><dd class="mono">{{ $versions['panel'] }}</dd>
            <dt>paneld (agent)</dt><dd class="mono">{{ $versions['agent'] }}</dd>
            <dt>PHP</dt><dd class="mono">{{ $versions['php'] }}</dd>
            <dt>Framework</dt><dd class="mono">Laravel {{ $versions['framework'] }}</dd>
            <dt>Node.js</dt><dd class="mono">{{ $node ?? '—' }}</dd>
        </dl>
    </div>
    <div class="card">
        <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) Updates kaise lagte hain</h3>
        <p class="help" style="margin:6px 0 0">AlphaCP self-hosted hai — har update ek signed installer wave
            hota hai (<span class="mono">alphacp-sync get &lt;commit&gt; … &lt;sha256&gt;</span>): pehle backup,
            phir install, phir health-check — fail ho to <strong>auto-rollback</strong>. Ab tak lage waves
            neeche (har installer apna <span class="mono">/var/log/alphacp-*.log</span> chhodta hai).</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0">@include('partials.icons', ['icon' => 'archive', 'cls' => 'hico']) Installed update waves ({{ count($waves) }})</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(260px,100%)" placeholder="Filter waves…" data-filter-rows="#acp-updates tbody tr" aria-label="Filter waves">
    </div>
    @if (! $agentOk)
        <p class="empty">Root agent offline — wave history nahi mil saki.</p>
    @elseif ($waves === [])
        <p class="empty">Koi wave log nahi mila.</p>
    @else
    <div class="table-wrap">
        <table id="acp-updates">
            <thead><tr><th>#</th><th>Wave</th><th>Log file</th><th>Status</th></tr></thead>
            <tbody>
            @foreach ($waves as $w)
                <tr>
                    <td class="mono">{{ $loop->iteration }}</td>
                    <td class="mono">{{ $w }}</td>
                    <td class="mono muted">/var/log/alphacp-{{ $w }}.log</td>
                    <td><span class="badge green">installed</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
