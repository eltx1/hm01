<section class="workspace-section reports-page" aria-labelledby="performance-heading">
    <div class="workspace-heading"><div><p class="eyebrow">Performance · {{ $performance['currency'] }}</p><h2 id="performance-heading">Your earnings at a glance</h2></div><span class="pill">{{ $performance['from'] }} — {{ $performance['to'] }}</span></div>
    <x-report-period :from="$performance['from']" :to="$performance['to']" />
    <p class="muted">Reporting dates follow each source’s reporting timezone.@if($performance['updated_at']) Last imported update: {{ $performance['updated_at']->format('Y-m-d H:i') }} ({{ config('app.timezone') }}).@endif</p>
    @if($performance['available'])
    <div class="report-metrics">
        <article class="report-kpi report-kpi-primary"><p class="eyebrow">Reported earnings</p><strong class="metric">{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['earnings_minor']) }}</strong><span class="muted">Your share, including estimates</span></article>
        <article class="report-kpi"><p class="eyebrow">Impressions</p><strong class="metric">{{ number_format($performance['impressions']) }}</strong><span class="muted">Ads shown</span></article>
        <article class="report-kpi"><p class="eyebrow">Clicks</p><strong class="metric">{{ number_format($performance['clicks']) }}</strong><span class="muted">Reported ad clicks</span></article>
        <article class="report-kpi"><p class="eyebrow">Earnings per 1,000 impressions</p><strong class="metric">{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['ecpm_minor']) }}</strong><span class="muted">eCPM · based on your earnings</span></article>
    </div>
    <p class="report-context muted">Estimated earnings: {{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['estimated_minor']) }} · Finalized earnings: {{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['finalized_minor']) }}. Statement adjustments and referral commissions are shown in Finance, not added to this performance total.</p>
    <article class="report-chart-card"><div class="report-card-heading"><div><p class="eyebrow">EARNINGS TREND</p><h3>Your earnings over time</h3></div><span class="report-state">{{ $performance['currency'] }} · Your share</span></div>
        <x-report-chart :rows="$performance['days']" date-key="label" value-key="earnings_minor" :currency="$performance['currency']" :from="$performance['from']" :to="$performance['to']" label="Daily publisher earnings" />
        <div class="report-chart-foot"><span><i aria-hidden="true"></i> Reported earnings</span><span>Missing days are left as gaps, not zero earnings.</span></div>
    </article>
    <div class="report-breakdowns">
        <article><h3>Your websites</h3>
            @foreach($performance['websites'] as $website)
                <div class="compact-row"><div><strong>{{ $website['label'] }}</strong><p>{{ number_format($website['impressions']) }} impressions · {{ number_format($website['clicks']) }} clicks</p></div><strong class="money">{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($website['earnings_minor']) }}</strong></div>
            @endforeach
        </article>
    </div>
    <details class="workspace-section"><summary>Daily report details</summary><div class="table-wrap"><table><caption class="sr-only">Daily performance in {{ $performance['currency'] }}</caption><thead><tr><th scope="col">Date</th><th scope="col">Impressions</th><th scope="col">Clicks</th><th scope="col">Estimated earnings</th><th scope="col">Finalized earnings</th></tr></thead><tbody>@foreach($performance['days'] as $day)<tr><th scope="row">{{ $day['label'] }}</th><td>{{ number_format($day['impressions']) }}</td><td>{{ number_format($day['clicks']) }}</td><td class="money">{{ \App\Support\Money::formatMinor($day['estimated_minor']) }}</td><td class="money">{{ \App\Support\Money::formatMinor($day['finalized_minor']) }}</td></tr>@endforeach</tbody></table></div></details>
    @else
        <x-empty-state title="No reports for these dates yet" description="Try another period. Missing reports do not mean zero earnings, and your existing statements remain available below." />
    @endif
</section>
