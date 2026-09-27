<section class="workspace-section reports-page" aria-labelledby="performance-heading">
    <div class="workspace-heading"><div><p class="eyebrow">Performance · {{ $performance['currency'] }}</p><h2 id="performance-heading">Your earnings at a glance</h2></div><span class="pill">{{ $performance['from'] }} — {{ $performance['to'] }}</span></div>
    <x-report-period :from="$performance['from']" :to="$performance['to']" :metrics="$reportMetrics" />
    <p class="muted">Reporting dates follow each source’s reporting timezone.@if($performance['updated_at']) Last imported update: {{ $performance['updated_at']->format('Y-m-d H:i') }} ({{ config('app.timezone') }}).@endif</p>
    @if($performance['available'])
    <div class="report-metrics">
        <article class="report-kpi report-kpi-primary"><p class="eyebrow">Reported earnings</p><strong class="metric">{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['earnings_minor']) }}</strong><span class="muted">Your share, including estimates</span></article>
        <article class="report-kpi"><p class="eyebrow">Impressions</p><strong class="metric">{{ number_format($performance['impressions']) }}</strong><span class="muted">Ads shown</span></article>
        <article class="report-kpi"><p class="eyebrow">Estimated earnings</p><strong class="metric">{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['estimated_minor']) }}</strong><span class="muted">May change after finalization</span></article>
        <article class="report-kpi"><p class="eyebrow">Finalized earnings</p><strong class="metric">{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['finalized_minor']) }}</strong><span class="muted">Before statement adjustments</span></article>
    </div>
    <x-report-performance-totals :totals="$performance" :currency="$performance['currency']" :publisher="true" />
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
            <details class="report-data-details"><summary>Website performance details</summary><x-report-performance-table :rows="$performance['websites']" :metrics="$reportMetrics" :currency="$performance['currency']" label="Website" caption="Publisher website performance" /></details>
        </article>
    </div>
    <details class="workspace-section report-data-details" open><summary>Daily report details</summary>
        <x-report-performance-table :rows="$performance['days']" :metrics="$reportMetrics" :currency="$performance['currency']" caption="Daily publisher performance" />
        <p class="report-footnote muted">Earnings include estimated and finalized reports. Download CSV for their separate daily amounts.</p>
    </details>
    @else
        <x-empty-state title="No reports for these dates yet" description="Try another period. Missing reports do not mean zero earnings, and your existing statements remain available below." />
    @endif
</section>
