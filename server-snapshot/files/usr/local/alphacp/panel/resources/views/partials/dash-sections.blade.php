<div class="card mt">
    <h3>@include('partials.icons', ['icon' => 'target', 'cls' => 'hico']) AlphaCP feature progress</h3>
    <div class="stat">
        <span class="num">{{ $progress['live'] }}</span>
        <span class="unit">tools live · {{ $progress['planned'] }} planned · {{ $progress['addon'] }} optional · total {{ $progress['total'] }}</span>
    </div>
    <div class="meter"><span style="width: {{ max(3, $progress['percent']) }}%"></span></div>
    <p class="help">Full checklist: <span class="mono">docs/09-cpanel-parity-checklist.md</span> — 208 items,
        har item apne step me live hota jayega.
        @if ($panelMode === 'cpanel')
            Account create / packages are Server Manager (admin) only — they are hidden here.
        @endif
    </p>
</div>

@foreach ($sections as $key => $section)
    @php
        $liveCount = collect($section['items'])->where('status', 'live')->count();
    @endphp
    <div class="card mt sect-card">
        <div class="sect-head">
            <h3>@include('partials.icons', ['icon' => $section['icon'] ?? $key, 'cls' => 'hico']) {{ $section['label'] }}</h3>
            <span class="count">{{ $liveCount }} live / {{ count($section['items']) }}</span>
            <button class="chev" type="button" aria-label="Toggle section">▾</button>
        </div>
        <div class="sect-body grid tiles">
            @foreach ($section['items'] as $item)
                @include('partials.tile', ['item' => $item])
            @endforeach
        </div>
    </div>
@endforeach
