@if($video['available'] || ($video['configured'] ?? false))
<section class="publisher-report-details" aria-labelledby="video-performance-heading">
    <div class="report-card-heading"><h2 id="video-performance-heading">Video performance</h2>@if($video['available'])<a class="hm-button-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'video_csv']) }}">Export Video CSV</a>@endif</div>
    <p class="muted">Separate from the main report. Video earnings are included in financial totals. Unfilled is Google's original selected-unit total across all sites, not exact-hostname requests.</p>
    <p class="muted">Reporting dates follow each selected network's reporting day.@if(count($video['timezones'] ?? []) > 1) Multiple source clocks: {{ implode(', ', $video['timezones']) }}. Days are grouped by source date, not converted to a common timezone.@elseif(count($video['timezones'] ?? []) === 1) Network timezone: {{ $video['timezones'][0] }}.@endif</p>
    @if($video['available'])
    <div class="report-metrics">
        @foreach(['impressions' => 'Video impressions', 'unfilled_impressions' => 'Video Unfilled (ad unit, all sites)', 'revenue_minor' => $publisher ? 'Video earnings' : 'Video gross revenue', 'ecpm_minor' => 'Video eCPM'] as $key => $label)
        <article class="report-kpi"><h3 class="eyebrow">{{ $label }}</h3><strong class="report-kpi-value">{{ $key === 'revenue_minor' ? \App\Support\Money::formatMinor($video[$key]) : \App\Services\Reporting\PerformanceMetrics::display($key, $video[$key]) }} @if(in_array($key, ['revenue_minor', 'ecpm_minor']))<small>{{ $video['currency'] }}</small>@endif</strong></article>
        @endforeach
    </div>
    <p class="muted">Video eCPM uses {{ $publisher ? 'your earnings after revenue share' : 'gross revenue before revenue share' }} per 1,000 Video impressions. {{ $video['has_estimates'] ? 'Includes estimates.' : 'Finalized reports.' }} — means unavailable.</p>
    <div class="table-wrap"><table><caption>Video daily performance</caption><thead><tr><th>Date</th><th>Video impressions</th><th>Video Unfilled (ad unit, all sites)</th><th>{{ $publisher ? 'Video earnings' : 'Video gross revenue' }} ({{ $video['currency'] }})</th><th>Video eCPM ({{ $video['currency'] }})</th>@unless($publisher)<th>Video publisher earnings</th><th>Video Horus margin</th>@endunless</tr></thead><tbody>
    @foreach($video['days']->reverse()->values() as $day)<tr><td>{{ $day['date'] }}@if($day['has_estimates']) · Estimated @endif</td><td>{{ \App\Services\Reporting\PerformanceMetrics::display('impressions', $day['impressions']) }}</td><td>{{ \App\Services\Reporting\PerformanceMetrics::display('unfilled_impressions', $day['unfilled_impressions']) }}</td><td>{{ \App\Support\Money::formatMinor($day['revenue_minor']) }}</td><td>{{ \App\Services\Reporting\PerformanceMetrics::display('ecpm_minor', $day['ecpm_minor']) }}</td>@unless($publisher)<td>{{ \App\Support\Money::formatMinor($day['publisher_earnings_minor']) }}</td><td>{{ \App\Support\Money::formatMinor($day['horus_earnings_minor']) }}</td>@endunless</tr>@endforeach
    </tbody></table></div>
    @else<p class="muted">Video reporting is enabled. Awaiting imported statistics for these dates.</p>@endif
</section>
@endif
