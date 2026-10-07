{{-- One dashboard tile = one row of docs/09-cpanel-parity-checklist.md --}}
@php
    $live    = ($item['status'] ?? 'step') === 'live';
    $addon   = ($item['status'] ?? 'step') === 'addon';
    $href    = $live ? route($item['route']) : null;
    $classes = 'cp-tile' . ($live ? ' live' : ' disabled');
    $icon    = \App\Support\NavIcon::glyph($icon ?? 'cog');
@endphp

@if ($live)
    <a class="{{ $classes }}" href="{{ $href }}" data-tool="{{ $item['name'] }}">
@else
    <div class="{{ $classes }}" data-tool="{{ $item['name'] }}"
         title="{{ $addon ? 'Optional module' : 'Step ' . $item['step'] . ' me aayega' }}">
@endif

    <span class="cp-tile-icon" aria-hidden="true">{{ $icon }}</span>
    <span class="cp-tile-body">
        <span class="name">{{ $item['name'] }}</span>
        <span class="sub">
            @if ($live)
                Manage
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
