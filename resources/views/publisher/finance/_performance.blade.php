<section class="reports-page publisher-reports" aria-labelledby="performance-heading">
    <header class="publisher-report-heading">
        <h2 id="performance-heading">Your earnings at a glance</h2>
        <p>Ad performance for your selected dates.</p>
    </header>
    <x-publisher-report-period :from="$performance['from']" :to="$performance['to']" :metrics="$reportMetrics" />
    @if($performance['available'])
    <div class="publisher-report-overview">
    <article class="publisher-earnings" aria-labelledby="publisher-earnings-heading">
        <div class="publisher-earnings-topline"><h3 id="publisher-earnings-heading">Your earnings</h3><span class="report-state">{{ $performance['has_estimates'] ? 'Includes estimates' : 'Finalized reports' }}</span></div>
        <p class="publisher-earnings-value"><span>{{ $performance['currency'] }}</span> {{ \App\Support\Money::formatMinor($performance['earnings_minor']) }}</p>
        <p class="publisher-earnings-note">Your revenue share is already applied.</p>
        <details class="publisher-earnings-breakdown">
            <summary>How this total is calculated</summary>
            <dl>
                <div><dt>Finalized earnings</dt><dd>{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['finalized_minor']) }}</dd></div>
                <div><dt>Estimated earnings <small>May still change</small></dt><dd>{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['estimated_minor']) }}</dd></div>
            </dl>
            <p>These two amounts make up the total above. Your payable balance, adjustments and referral earnings are in your monthly statements.</p>
        </details>
    </article>
    <x-publisher-report-metrics :totals="$performance" :currency="$performance['currency']" />
    </div>
    <article class="report-chart-card">
        <div class="report-card-heading"><h3>Earnings over time</h3><span class="report-state">{{ $performance['currency'] }} · Your earnings</span></div>
        <x-report-chart :rows="$performance['days']" date-key="label" value-key="earnings_minor" :currency="$performance['currency']" :from="$performance['from']" :to="$performance['to']" label="Daily publisher earnings" :responsive="true" />
        <div class="report-chart-foot"><span><i aria-hidden="true"></i> Your earnings</span><span>Missing reports appear as gaps.</span></div>
    </article>
    <section class="publisher-report-details" aria-labelledby="daily-report-heading">
        <div class="report-card-heading"><div><h3 id="daily-report-heading">Daily breakdown</h3><p class="muted">Your earnings and ad performance, day by day.</p></div></div>
        <x-publisher-report-columns :metrics="$reportMetrics" />
        <x-report-performance-table :rows="$performance['days']->reverse()->values()" :metrics="$reportMetrics" :currency="$performance['currency']" :mobile-cards="true" caption="Daily publisher performance" />
    </section>
    <section class="publisher-report-details" aria-labelledby="website-report-heading">
        <div class="report-card-heading"><h3 id="website-report-heading">Website breakdown</h3><span class="report-state">{{ $performance['websites']->count() }} {{ $performance['websites']->count() === 1 ? 'website' : 'websites' }}</span></div>
        <x-report-performance-table :rows="$performance['websites']" :metrics="$reportMetrics" :currency="$performance['currency']" label="Website" :mobile-cards="true" caption="Publisher website performance" />
    </section>
    @else
        <x-empty-state title="No reports for these dates yet" description="Try another period. Missing reports do not mean zero earnings." />
        <x-publisher-report-columns :metrics="$reportMetrics" />
    @endif
    <details class="publisher-report-about">
        <summary>About these numbers</summary>
        <p>Earnings and CPM use your share only. Estimates may change when reports are finalized. Download CSV for separate estimated and finalized daily amounts.</p>
        <p>CTR is clicks divided by impressions. Active View is viewable impressions divided by measurable impressions. Unfilled impressions are supplied by the ad source.</p>
        <p>Unavailable means the selected reports do not contain enough data to calculate that metric. It does not mean zero.</p>
        <p>Reporting dates follow each source’s timezone.@if($performance['updated_at']) Last updated: {{ $performance['updated_at']->format('j M Y, H:i') }} ({{ config('app.timezone') }}).@endif</p>
    </details>
    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <aside class="report-finance-strip"><div><h3>Looking for your payment?</h3><p class="muted">See your payable balance and invoices in monthly statements.</p></div><a class="hm-button-secondary" href="{{ route('publisher.finance.statements.index') }}">View statements →</a></aside>
    @endif
</section>
