@props(['rows', 'dateKey' => 'date', 'valueKey' => 'gross_revenue_minor', 'currency', 'from', 'to', 'label' => 'Daily revenue', 'responsive' => false])
@php
    $start = \Carbon\CarbonImmutable::parse($from)->startOfDay();
    $end = \Carbon\CarbonImmutable::parse($to)->startOfDay();
    $span = max(1, $start->diffInDays($end));
    $values = collect($rows)->pluck($valueKey);
    $low = min(0, (int) $values->min());
    $high = max(1, (int) $values->max());
    $range = $high - $low;
    $points = collect($rows)->map(function ($row) use ($dateKey, $valueKey, $start, $span, $high, $range) {
        $date = \Carbon\CarbonImmutable::parse($row[$dateKey]);
        return ['date' => $date->toDateString(), 'day' => (int) $start->diffInDays($date), 'value' => (int) $row[$valueKey],
            'x' => round(72 + $start->diffInDays($date) / $span * 796, 2),
            'y' => round(24 + ($high - $row[$valueKey]) / $range * 172, 2)];
    })->values();
@endphp
<div class="report-chart {{ $responsive ? 'report-chart--responsive' : '' }}" tabindex="0" role="region" aria-label="{{ $label }} chart">
    @foreach($responsive ? ['desktop', 'mobile'] : ['desktop'] as $layout)
        @php
            $mobile = $layout === 'mobile';
            $width = $mobile ? 360 : 900;
            $height = $mobile ? 190 : 238;
            $left = $mobile ? 52 : 72;
            $right = $mobile ? 344 : 868;
            $plotHeight = $mobile ? 130 : 172;
            $plotPoints = $points->map(fn ($point) => [...$point,
                'x' => round($left + $point['day'] / $span * ($right - $left), 2),
                'y' => round(24 + ($high - $point['value']) / $range * $plotHeight, 2),
            ]);
            $path = '';
            $previousDay = null;
            foreach ($plotPoints as $point) {
                $path .= ($previousDay !== null && $point['day'] === $previousDay + 1 ? ' L ' : ' M ').$point['x'].' '.$point['y'];
                $previousDay = $point['day'];
            }
        @endphp
        <svg class="report-chart-{{ $layout }}" viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label }} in {{ $currency }} from {{ $start->toDateString() }} to {{ $end->toDateString() }}. Missing days are left as gaps.">
            @foreach([0, 1, 2, 3] as $tick)
                @php($y = 24 + $tick / 3 * $plotHeight)
                <line class="report-chart-grid" x1="{{ $left }}" x2="{{ $right }}" y1="{{ $y }}" y2="{{ $y }}" />
                <text class="report-chart-label" x="{{ $left - 10 }}" y="{{ $y + 4 }}" text-anchor="end">{{ \App\Support\Money::formatMinor((int) round($high - $tick / 3 * $range)) }}</text>
            @endforeach
            <path class="report-chart-line" d="{{ $path }}" />
            @foreach($plotPoints as $point)
                <circle class="report-chart-point" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="{{ $points->count() > 90 ? 2 : 4 }}"><title>{{ $point['date'] }}: {{ $currency }} {{ \App\Support\Money::formatMinor($point['value']) }}</title></circle>
            @endforeach
            <text class="report-chart-label" x="{{ $left }}" y="{{ $height - 12 }}">{{ $start->format('d M') }}</text>
            @if($start->ne($end))<text class="report-chart-label" x="{{ $right }}" y="{{ $height - 12 }}" text-anchor="end">{{ $end->format('d M') }}</text>@endif
        </svg>
    @endforeach
</div>
