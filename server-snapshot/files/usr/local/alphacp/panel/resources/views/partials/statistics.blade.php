{{-- cPanel ka right-hand "Statistics" column: used / limit (docs/10-ui-parity-design.md §3) --}}
@php
    $stats = $stats ?? [];
    $fmt = static function (int $value, ?string $unit): string {
        if ($value < 0) {
            return '∞';
        }
        return $unit !== null ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $unit : (string) $value;
    };
@endphp

@if (! empty($stats))
    <section class="cp-panel" aria-labelledby="cp-statistics">
        <header class="cp-panel-head">
            <h2 id="cp-statistics">Statistics</h2>
        </header>
        <ul class="cp-stats">
            @foreach ($stats as $row)
                @php
                    $used  = (int) ($row['used'] ?? 0);
                    $limit = (int) ($row['limit'] ?? -1);
                    $unit  = $row['unit'] ?? null;
                    $pct   = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0;
                @endphp
                <li>
                    <span class="cp-info-label">{{ $row['label'] }}</span>
                    <span class="cp-stat-line">
                        <span class="used">{{ $fmt($used, $unit) }}</span>
                        <span class="sep">/</span>
                        <span class="limit">{{ $fmt($limit, $unit) }}</span>
                    </span>
                    @if ($limit > 0)
                        <span class="meter slim {{ $pct > 85 ? 'amber' : 'green' }}"><span style="width: {{ max(2, $pct) }}%"></span></span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
