@props(['ranking', 'currency' => 'USD'])
@if(collect($ranking)->isNotEmpty())
<section class="publisher-report-details ad-format-ranking" aria-labelledby="ad-format-ranking-heading">
    <div class="report-card-heading"><h3 id="ad-format-ranking-heading">Website ranking by eCPM per format</h3><span class="report-state">Ad Exchange · {{ $currency }} · min. {{ number_format((int) config('reporting.ad_format_ranking.min_impressions', 1000)) }} impressions</span></div>
    @foreach($ranking as $format => $group)
        <details class="report-disclosure" @if($loop->first) open @endif data-ad-format-ranking="{{ $format }}">
            <summary>{{ $group['label'] }} · {{ $group['sites']->count() }} {{ \Illuminate\Support\Str::plural('website', $group['sites']->count()) }} · network median eCPM {{ \App\Support\Money::formatMinor($group['median_ecpm_minor']) }} {{ $currency }}</summary>
            <div class="table-wrap" tabindex="0" role="region" aria-label="{{ $group['label'] }} ranking">
                <table>
                    <caption class="sr-only">{{ $group['label'] }} websites ranked by eCPM</caption>
                    <thead><tr><th scope="col">#</th><th scope="col">Website</th><th scope="col">Publisher</th><th scope="col">Impressions</th><th scope="col">eCPM ({{ $currency }})</th><th scope="col">vs median</th><th scope="col">Revenue ({{ $currency }})</th></tr></thead>
                    <tbody>
                    @foreach($group['sites'] as $row)
                        <tr><td>{{ $loop->iteration }}</td>
                            <th scope="row">@if($row['publisher_id'])<a class="text-link" href="{{ route('admin.reporting.websites.show', $row['site_id']) }}">{{ $row['site'] }}</a>@else{{ $row['site'] }}@endif<span class="muted"> · {{ $row['domain'] }}</span></th>
                            <td>{{ $row['publisher'] }}</td><td>{{ number_format($row['impressions']) }}</td>
                            <td class="money">{{ \App\Support\Money::formatMinor($row['ecpm_minor']) }}</td>
                            <td><x-report-delta metric="ecpm_minor" :current="$row['ecpm_minor']" :previous="$group['median_ecpm_minor'] ?: null" /></td>
                            <td class="money">{{ \App\Support\Money::formatMinor($row['revenue_minor']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endforeach
</section>
@endif
