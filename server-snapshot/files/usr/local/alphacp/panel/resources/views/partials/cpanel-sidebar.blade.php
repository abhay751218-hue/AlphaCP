{{-- cPanel Jupiter-jaisa left navigation: grouped text links (live tools only) --}}
@php
    $cats = \App\Support\ModuleCatalog::sectionsFor(auth()->user());
@endphp
<div class="side-card">
    <div class="side-head">{{ config('acp.brand.name', 'AlphaCP') }} — Tools</div>
    @foreach ($cats as $key => $section)
        @php
            $liveItems = collect($section['items'])->where('status', 'live');
        @endphp
        @if ($liveItems->isNotEmpty())
            <h4>{{ $section['label'] }}</h4>
            @foreach ($liveItems as $item)
                <a href="{{ route($item['route']) }}">{{ $item['name'] }}</a>
            @endforeach
        @endif
    @endforeach
</div>
