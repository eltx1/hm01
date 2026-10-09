@props(['rows', 'metrics', 'currency', 'labelKey' => 'label', 'label' => 'Date', 'revenueKey' => 'earnings_minor', 'revenueLabel' => 'Your earnings', 'caption' => 'Performance details', 'mobileCards' => false])
<div class="table-wrap report-performance-table {{ $label === 'Date' ? 'report-performance-table--date' : '' }} {{ $mobileCards ? 'report-performance-table--responsive' : '' }}" tabindex="0" role="region" aria-label="{{ $caption }}">
    <table>
        <caption class="sr-only">{{ $caption }} · {{ $currency }}</caption>
        <thead><tr><th scope="col">{{ $label }}</th>
            @foreach($metrics as $metric)<th scope="col">{{ \App\Services\Reporting\PerformanceMetrics::label($metric, ['has_site_ad_exchange' => collect($rows)->contains(fn ($row) => $row['has_site_ad_exchange'] ?? false)]) }}@if($metric === 'ecpm_minor') <small>({{ $currency }})</small>@endif</th>@endforeach
            <th scope="col">{{ $revenueLabel }} <small>({{ $currency }})</small></th>
        </tr></thead>
        <tbody>@forelse($rows as $row)<tr><th scope="row">{{ $row[$labelKey] }}@if(array_key_exists('has_estimates', $row))<span class="muted"> · {{ $row['has_estimates'] ? 'Includes estimates · Awaiting finalization' : 'Finalized' }}</span>@endif</th>
            @foreach($metrics as $metric)<td @if(!$mobileCards && $row[$metric] === null) title="Not reported for the full period, or no eligible denominator" @endif>{{ $mobileCards && $row[$metric] === null ? 'Unavailable' : \App\Services\Reporting\PerformanceMetrics::display($metric, $row[$metric]) }}</td>@endforeach
            <td class="money">{{ \App\Support\Money::formatMinor((int) $row[$revenueKey]) }}</td>
        </tr>@empty<tr><td colspan="{{ count($metrics) + 2 }}">No reports for these dates.</td></tr>@endforelse</tbody>
    </table>
</div>
@if($mobileCards)
<div class="publisher-report-mobile-rows" role="region" aria-label="{{ $caption }}">
    @forelse($rows as $row)
        <div class="publisher-report-mobile-row">
            <div class="publisher-report-mobile-row-heading">
                <span><strong>{{ $label === 'Date' ? \Carbon\CarbonImmutable::parse($row[$labelKey])->format('j M Y') : $row[$labelKey] }}</strong>@if(array_key_exists('has_estimates', $row))<small>{{ $row['has_estimates'] ? 'Includes estimates · Awaiting finalization' : 'Finalized' }}</small>@endif</span>
                <span class="publisher-row-earnings"><small>{{ $revenueLabel }}</small><strong>{{ \App\Support\Money::formatMinor((int) $row[$revenueKey]) }} <small>{{ $currency }}</small></strong></span>
            </div>
            <dl>@foreach($metrics as $metric)<div><dt>{{ \App\Services\Reporting\PerformanceMetrics::label($metric, $row) }}</dt><dd>{{ $row[$metric] === null ? 'Unavailable' : \App\Services\Reporting\PerformanceMetrics::display($metric, $row[$metric]) }}@if($metric === 'ecpm_minor' && $row[$metric] !== null) <small>{{ $currency }}</small>@endif</dd></div>@endforeach</dl>
        </div>
    @empty<p class="muted">No reports for these dates.</p>@endforelse
</div>
@endif
