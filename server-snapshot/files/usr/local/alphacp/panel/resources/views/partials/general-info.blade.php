{{-- cPanel ka right-hand "General Information" column (docs/10-ui-parity-design.md §3) --}}
@php
    $rows  = $rows ?? [];
    $title = $title ?? 'General Information';
@endphp

<section class="cp-panel" aria-labelledby="cp-general-info">
    <header class="cp-panel-head">
        <h2 id="cp-general-info">{{ $title }}</h2>
    </header>
    <ul class="cp-info">
        @foreach ($rows as $row)
            <li>
                <span class="cp-info-label">{{ $row['label'] }}</span>
                <span class="cp-info-value {{ ! empty($row['mono']) ? 'mono' : '' }}">
                    @if (! empty($row['href']))
                        <a href="{{ $row['href'] }}">{{ $row['value'] }}</a>
                    @else
                        {{ $row['value'] }}
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
</section>
