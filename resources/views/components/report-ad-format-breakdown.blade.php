@props(['breakdown', 'metrics', 'publisher' => false, 'compact' => false, 'fullUrl' => null, 'previous' => null, 'period' => null])
@php
    $currency = $breakdown['currency'];
    $revenueKey = $breakdown['revenue_key'];
    $formats = $breakdown['formats'];
    $notBrokenDown = $breakdown['not_broken_down'];
    $dim = $breakdown['dimension_label'] ?? 'ad format';
    $Dim = ucfirst($dim);
    $dimPlural = ['ad format' => 'formats', 'country' => 'countries', 'device' => 'devices'][$dim] ?? 'values';
    $isFormat = ($breakdown['dimension'] ?? 'FORMAT') === 'FORMAT';
    $previousByFormat = $previous && $previous['available'] ? collect($previous['formats'])->keyBy('key') : null;
@endphp
<div class="ad-format-breakdown">
    @if(!$breakdown['available'])
        <x-empty-state title="No reports for these dates" description="Try another reporting period. Missing data does not mean zero earnings." />
    @else
        @if($breakdown['has_estimates'])<p class="report-footnote muted">Includes estimates awaiting finalization; amounts may change.</p>@endif
        @if(!$breakdown['has_breakdown'])
            <p class="ad-format-notice">The {{ $dim }} breakdown for this period is still being prepared from Google Ad Manager. Totals below match your main report; formats will appear automatically after the next synchronization.</p>
        @endif

        <section class="ad-format-share" aria-labelledby="ad-format-share-heading">
            <div class="report-card-heading">
                <h3 id="ad-format-share-heading">{{ $breakdown['revenue_label'] }} by {{ $dim }}</h3>
                <span class="report-state">{{ \App\Support\Money::formatMinor((int) $breakdown['totals'][$revenueKey]) }} {{ $currency }} total @if($previousByFormat)<x-report-delta :metric="$revenueKey" :current="$breakdown['totals'][$revenueKey]" :previous="$previous['totals'][$revenueKey]" :period="$period" />@endif</span>
            </div>
            <ul class="ad-format-share-list">
                @foreach($formats as $row)
                    <li class="ad-format-share-item {{ $row['key'] === \App\Services\Reporting\AdFormatCatalog::NOT_BROKEN_DOWN ? 'ad-format-share-item--muted' : '' }}" data-ad-format="{{ $row['key'] }}">
                        <div class="ad-format-share-label">
                            <strong>{{ $row['label'] }}</strong>
                            <span>{{ \App\Support\Money::formatMinor((int) $row[$revenueKey]) }} <small>{{ $currency }}</small>@if($row['share_bp'] !== null) · {{ number_format($row['share_bp'] / 100, 1) }}%@endif
                                @if($previousByFormat && $row['key'] !== \App\Services\Reporting\AdFormatCatalog::NOT_BROKEN_DOWN)<x-report-delta :metric="$revenueKey" :current="$row[$revenueKey]" :previous="$previousByFormat->get($row['key'])[$revenueKey] ?? null" :period="$period" />@endif</span>
                        </div>
                        <div class="ad-format-share-bar" aria-hidden="true"><i style="width: {{ $row['share_bp'] === null ? 0 : max(0, min(100, $row['share_bp'] / 100)) }}%"></i></div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="publisher-report-details" aria-labelledby="ad-format-table-heading">
            <div class="report-card-heading"><h3 id="ad-format-table-heading">Performance by {{ $dim }}</h3><span class="report-state">{{ $formats->where('key', '!=', \App\Services\Reporting\AdFormatCatalog::NOT_BROKEN_DOWN)->count() }} {{ $formats->where('key', '!=', \App\Services\Reporting\AdFormatCatalog::NOT_BROKEN_DOWN)->count() === 1 ? \Illuminate\Support\Str::singular($dimPlural) : $dimPlural }}</span></div>
            <x-report-performance-table :rows="$formats->concat([$breakdown['totals']])->values()" :metrics="$metrics" :currency="$currency" label="{{ $Dim }}" :revenue-key="$revenueKey" :revenue-label="$breakdown['revenue_label']" :mobile-cards="true" caption="Performance by {{ $dim }}" />
            @unless($publisher)
                <div class="table-wrap" tabindex="0" role="region" aria-label="Revenue split by {{ $dim }}">
                    <table>
                        <caption class="sr-only">Revenue split by {{ $dim }} · {{ $currency }}</caption>
                        <thead><tr><th scope="col">{{ $Dim }}</th><th scope="col">Gross revenue <small>({{ $currency }})</small></th><th scope="col">Publisher earnings <small>({{ $currency }})</small></th><th scope="col">Horus margin <small>({{ $currency }})</small></th></tr></thead>
                        <tbody>
                        @foreach($formats as $row)
                            <tr><th scope="row">{{ $row['label'] }}</th><td class="money">{{ \App\Support\Money::formatMinor((int) $row['gross_revenue_minor']) }}</td><td class="money">{{ \App\Support\Money::formatMinor((int) $row['publisher_earnings_minor']) }}</td><td class="money">{{ \App\Support\Money::formatMinor((int) $row['horus_earnings_minor']) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endunless
        </section>

        @if(!$compact && $breakdown['has_breakdown'] && ($breakdown['dimension'] ?? 'FORMAT') !== 'COUNTRY')<x-report-format-trend :breakdown="$breakdown" />@endif
        @if(!$compact && $breakdown['days']->isNotEmpty() && ($breakdown['dimension'] ?? 'FORMAT') !== 'COUNTRY')
        <section class="publisher-report-details" aria-labelledby="ad-format-daily-heading">
            <div class="report-card-heading"><h3 id="ad-format-daily-heading">Daily breakdown by {{ $dim }}</h3><span class="report-state">{{ $breakdown['revenue_label'] }} · {{ $currency }}</span></div>
            <div class="table-wrap ad-format-daily" tabindex="0" role="region" aria-label="Daily {{ strtolower($breakdown['revenue_label']) }} by {{ $dim }}">
                <table>
                    <caption class="sr-only">Daily {{ strtolower($breakdown['revenue_label']) }} by {{ $dim }} · {{ $currency }}</caption>
                    <thead><tr><th scope="col">Date</th>@foreach($breakdown['format_columns'] as $column)<th scope="col">{{ $column['label'] }}</th>@endforeach<th scope="col">Total</th></tr></thead>
                    <tbody>
                    @foreach($breakdown['days'] as $day)
                        <tr><th scope="row">{{ \Carbon\CarbonImmutable::parse($day['date'])->format('j M Y') }}</th>
                            @foreach($breakdown['format_columns'] as $column)<td class="money">{{ $day['cells'][$column['key']] === null ? '—' : \App\Support\Money::formatMinor($day['cells'][$column['key']]) }}</td>@endforeach
                            <td class="money"><strong>{{ \App\Support\Money::formatMinor($day['total']) }}</strong></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        @endif

        @if($compact)
        <p class="report-footnote muted">Same reported amounts as this report, split by Google Ad Manager {{ $isFormat ? 'inventory format' : $dim }}.@if($notBrokenDown) “Not broken down” covers amounts without format data from Google.@endif
            @if($fullUrl)<a class="text-link" href="{{ $fullUrl }}">Open full {{ $dim }} breakdown →</a>@endif</p>
        @else
        <p class="report-footnote muted">
            {{ $isFormat ? "Ad formats come from Google Ad Manager's inventory format" : ucfirst($dimPlural)." come from Google Ad Manager's ".($dim === 'country' ? 'country' : 'device category') }} for this website's reporting ad unit (exact website only).
            {{ $publisher ? 'Your earnings' : 'Gross revenue, publisher earnings and margin' }} are the same reported amounts as the main report, split across formats in proportion to the revenue Google reported for each format, so the formats always add up to the main total.
            Impressions, clicks and requests per format are Google's own counters.
            @if($notBrokenDown)
                “Not broken down” covers amounts without format data from Google, such as other demand sources, amounts not tied to one website, or days still being synchronized.
            @endif
            Video and mobile apps are reported separately.
        </p>
        @endif
        @if($breakdown['synced_at'])<p class="publisher-report-updated">{{ ucfirst($dimPlural) }} last synchronized: {{ \Carbon\CarbonImmutable::parse($breakdown['synced_at'])->setTimezone(config('app.timezone'))->format('j M Y, H:i') }} ({{ config('app.timezone') }}).</p>@endif
    @endif
</div>
