@props(['breakdown'])
@php
    // Colour follows the format, never its rank: a fixed slot per known format.
    $slots = ['BANNER' => 1, 'INTERSTITIAL' => 2, 'ANCHOR' => 3, 'REWARDED' => 4, 'SIDE_RAIL' => 5, 'NATIVE' => 6, 'VIDEO' => 7, 'REWARDED_INTERSTITIAL' => 8];
    $columns = collect($breakdown['format_columns']);
    $days = collect($breakdown['days'])->sortBy('date')->values();
    $currency = $breakdown['currency'];
    $width = 900; $height = 260; $left = 64; $right = 884; $top = 16; $plot = 196;
    $max = max(1, (int) $days->max(fn ($day) => collect($day['cells'])->sum(fn ($value) => max(0, (int) $value))));
    $count = max(1, $days->count());
    $slotWidth = ($right - $left) / $count;
    $barWidth = max(2, min(40, $slotWidth - 4));
@endphp
@if($days->isNotEmpty() && $columns->isNotEmpty())
<figure class="report-format-trend" aria-labelledby="format-trend-title">
    <figcaption class="report-card-heading"><h3 id="format-trend-title">{{ $breakdown['revenue_label'] }} by ad format over time</h3><span class="report-state">{{ $currency }} · daily</span></figcaption>
    <ul class="report-format-legend" aria-label="Ad formats">
        @foreach($columns as $column)
            <li><i class="format-swatch format-slot-{{ $slots[$column['key']] ?? ($column['key'] === \App\Services\Reporting\AdFormatCatalog::NOT_BROKEN_DOWN ? 'none' : 8) }}" aria-hidden="true"></i>{{ $column['label'] }}</li>
        @endforeach
    </ul>
    <div class="report-format-trend-plot" tabindex="0" role="region" aria-label="Daily {{ strtolower($breakdown['revenue_label']) }} by ad format chart">
        <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="Stacked daily {{ strtolower($breakdown['revenue_label']) }} by ad format in {{ $currency }}. The daily table below lists every value.">
            @foreach([0, 1, 2, 3] as $tick)
                @php($y = $top + $tick / 3 * $plot)
                <line class="report-chart-grid" x1="{{ $left }}" x2="{{ $right }}" y1="{{ $y }}" y2="{{ $y }}" />
                <text class="report-chart-label" x="{{ $left - 8 }}" y="{{ $y + 4 }}" text-anchor="end">{{ \App\Support\Money::formatMinor((int) round($max - $tick / 3 * $max)) }}</text>
            @endforeach
            @foreach($days as $index => $day)
                @php($x = $left + $index * $slotWidth + ($slotWidth - $barWidth) / 2)
                @php($base = $top + $plot)
                <g class="format-trend-day">
                    @foreach($columns as $column)
                        @php($value = max(0, (int) ($day['cells'][$column['key']] ?? 0)))
                        @continue($value === 0)
                        @php($segment = $value / $max * $plot)
                        <rect class="format-segment format-slot-{{ $slots[$column['key']] ?? ($column['key'] === \App\Services\Reporting\AdFormatCatalog::NOT_BROKEN_DOWN ? 'none' : 8) }}"
                            x="{{ round($x, 2) }}" y="{{ round($base - $segment, 2) }}" width="{{ round($barWidth, 2) }}" height="{{ round(max(1, $segment), 2) }}" rx="{{ $barWidth >= 8 ? 3 : 1 }}"><title>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('j M Y') }} · {{ $column['label'] }}: {{ \App\Support\Money::formatMinor($value) }} {{ $currency }}</title></rect>
                        @php($base -= $segment)
                    @endforeach
                    <rect class="format-trend-hit" x="{{ round($left + $index * $slotWidth, 2) }}" y="{{ $top }}" width="{{ round($slotWidth, 2) }}" height="{{ $plot }}"><title>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('j M Y') }} · total {{ \App\Support\Money::formatMinor($day['total']) }} {{ $currency }}</title></rect>
                </g>
            @endforeach
            <text class="report-chart-label" x="{{ $left }}" y="{{ $height - 22 }}">{{ \Carbon\CarbonImmutable::parse($days->first()['date'])->format('j M') }}</text>
            @if($days->count() > 1)<text class="report-chart-label" x="{{ $right }}" y="{{ $height - 22 }}" text-anchor="end">{{ \Carbon\CarbonImmutable::parse($days->last()['date'])->format('j M') }}</text>@endif
        </svg>
    </div>
</figure>
@endif
