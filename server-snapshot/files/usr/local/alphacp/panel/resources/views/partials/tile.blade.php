{{-- One dashboard tile = one row of docs/09-cpanel-parity-checklist.md --}}
@php
    $live    = ($item['status'] ?? 'step') === 'live';
    $addon   = ($item['status'] ?? 'step') === 'addon';
    $href    = $live ? route($item['route']) : null;
    $classes = 'tile' . ($live ? ' live' : ' disabled');
@endphp

@if ($live)
    <a class="{{ $classes }}" href="{{ $href }}">
@else
    <div class="{{ $classes }}" title="{{ $addon ? 'Optional module' : 'Step ' . $item['step'] . ' me aayega' }}">
@endif

    <span class="tchip">@include('partials.icons', ['icon' => $item['icon'] ?? (($item['name'] ?? '') . ' ' . ($item['route'] ?? '')), 'cls' => 'tico'])</span>
    <span>
        <span class="name">{{ $item['name'] }}</span>
        <span class="sub">
            @if ($live)
                Ready
            @elseif ($addon)
                Optional ({{ $item['step'] }})
            @else
                {{ $item['step'] }} me aayega
            @endif
        </span>
    </span>

@if ($live)
    </a>
@else
    </div>
@endif
