
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
