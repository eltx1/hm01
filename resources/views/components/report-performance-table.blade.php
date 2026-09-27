@props(['rows', 'metrics', 'currency', 'labelKey' => 'label', 'label' => 'Date', 'revenueKey' => 'earnings_minor', 'revenueLabel' => 'Your earnings', 'caption' => 'Performance details'])
<div class="table-wrap report-performance-table" tabindex="0" role="region" aria-label="{{ $caption }}">
    <table>
        <caption class="sr-only">{{ $caption }} · {{ $currency }}</caption>
        <thead><tr><th scope="col">{{ $label }}</th>
            @foreach($metrics as $metric)<th scope="col">{{ \App\Services\Reporting\PerformanceMetrics::COLUMNS[$metric] }}@if($metric === 'ecpm_minor') <small>({{ $currency }})</small>@endif</th>@endforeach
            <th scope="col">{{ $revenueLabel }} <small>({{ $currency }})</small></th>
        </tr></thead>
        <tbody>@forelse($rows as $row)<tr><th scope="row">{{ $row[$labelKey] }}</th>
            @foreach($metrics as $metric)<td @if($row[$metric] === null) title="Not reported for the full period, or no eligible denominator" @endif>{{ \App\Services\Reporting\PerformanceMetrics::display($metric, $row[$metric]) }}</td>@endforeach
            <td class="money">{{ \App\Support\Money::formatMinor((int) $row[$revenueKey]) }}</td>
        </tr>@empty<tr><td colspan="{{ count($metrics) + 2 }}">No reports for these dates.</td></tr>@endforelse</tbody>
    </table>
</div>
