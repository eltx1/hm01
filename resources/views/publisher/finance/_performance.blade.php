<section class="reports-page publisher-reports" aria-labelledby="performance-heading">
    <header class="publisher-report-heading">
        <h2 id="performance-heading">Your earnings at a glance</h2>
    </header>
    <x-publisher-report-period :from="$performance['from']" :to="$performance['to']" :metrics="$reportMetrics" />
    @php($hasVideo = $performance['video']['available'] || ($performance['video']['has_configuration'] ?? $performance['video']['configured'] ?? false))
    @if($hasVideo)
    <nav class="report-channel-nav" aria-label="Report sections"><a href="#main-performance">Main performance</a><a href="#video-performance">Video performance</a></nav>
    @if($performance['available'] || $performance['video']['available'])
    <aside class="report-reconciliation" aria-label="Combined reported earnings"><h3>Reported earnings for this period</h3><dl>
        <div><dt>Main earnings</dt><dd>{{ $performance['available'] ? \App\Support\Money::formatMinor($performance['earnings_minor']) : 'No imported data' }} @if($performance['available']){{ $performance['currency'] }}@endif</dd></div>
        <div><dt>Video earnings</dt><dd>{{ $performance['video']['available'] ? \App\Support\Money::formatMinor($performance['video']['revenue_minor']) : 'No imported data' }} @if($performance['video']['available']){{ $performance['currency'] }}@endif</dd></div>
        <div><dt>Total reported earnings</dt><dd>{{ \App\Support\Money::formatMinor($performance['earnings_minor'] + $performance['video']['revenue_minor']) }} {{ $performance['currency'] }}</dd></div>
    </dl><p>Main and Video earnings are counted once. These are imported reports, before statement adjustments; this is not your payable balance.</p></aside>
    @endif
    <header id="main-performance" class="report-main-heading"><h2>Main performance</h2><p>Your primary reports, excluding independent Video results.</p></header>
    @endif
    @if($performance['available'])
    <div class="publisher-report-overview">
    <article class="publisher-earnings" aria-labelledby="publisher-earnings-heading">
        <div class="publisher-earnings-topline"><h3 id="publisher-earnings-heading">{{ $hasVideo ? 'Main earnings' : 'Your earnings' }}</h3><span class="report-state">{{ $performance['has_estimates'] ? 'Includes estimates' : 'Finalized reports' }}</span></div>
        <p class="publisher-earnings-value"><span>{{ $performance['currency'] }}</span> {{ \App\Support\Money::formatMinor($performance['earnings_minor']) }}</p>
        <details class="publisher-earnings-breakdown">
            <summary>Earnings breakdown</summary>
            <dl>
                <div><dt>Finalized earnings</dt><dd>{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['finalized_minor']) }}</dd></div>
                <div><dt>Estimated earnings</dt><dd>{{ $performance['currency'] }} {{ \App\Support\Money::formatMinor($performance['estimated_minor']) }}</dd></div>
            </dl>
        </details>
    </article>
    <x-publisher-report-metrics :totals="$performance" :currency="$performance['currency']" />
    </div>
    <x-report-unmatched-help :totals="$performance" />
    <article class="report-chart-card">
        <div class="report-card-heading"><h3>{{ $hasVideo ? 'Main earnings over time' : 'Earnings over time' }}</h3><span class="report-state">{{ $performance['currency'] }} · Your earnings</span></div>
        <x-report-chart :rows="$performance['days']" date-key="label" value-key="earnings_minor" :currency="$performance['currency']" :from="$performance['from']" :to="$performance['to']" label="Daily publisher earnings" :responsive="true" />
        <div class="report-chart-foot"><span><i aria-hidden="true"></i> Your earnings</span></div>
    </article>
    <section class="publisher-report-details" aria-labelledby="daily-report-heading">
        <div class="report-card-heading"><h3 id="daily-report-heading">Daily breakdown</h3></div>
        <x-publisher-report-columns :metrics="$reportMetrics" />
        <x-report-performance-table :rows="$performance['days']->reverse()->values()" :metrics="$reportMetrics" :currency="$performance['currency']" :mobile-cards="true" caption="Daily publisher performance" />
    </section>
    <section class="publisher-report-details" aria-labelledby="website-report-heading">
        <div class="report-card-heading"><h3 id="website-report-heading">Website breakdown</h3><span class="report-state">{{ $performance['websites']->count() }} {{ $performance['websites']->count() === 1 ? 'website' : 'websites' }}</span></div>
        <x-report-performance-table :rows="$performance['websites']" :metrics="$reportMetrics" :currency="$performance['currency']" label="Website" :mobile-cards="true" caption="Publisher website performance" />
    </section>
    @else
        <x-empty-state :title="$hasVideo ? 'No main reports for these dates' : 'No reports for these dates yet'" :description="$hasVideo ? 'Independent Video results appear below.' : 'Try another period.'" />
        <x-publisher-report-columns :metrics="$reportMetrics" />
    @endif
    <x-video-performance :video="$performance['video']" :publisher="true" :from="$performance['from']" :to="$performance['to']" />
    @if($performance['updated_at'])
        <p class="publisher-report-updated">{{ $hasVideo ? 'Main last updated:' : 'Last updated:' }} {{ $performance['updated_at']->format('j M Y, H:i') }} ({{ config('app.timezone') }}).</p>
    @endif
    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <aside class="report-finance-strip"><h3>Monthly statements</h3><a class="hm-button-secondary" href="{{ route('publisher.finance.statements.index') }}">View statements →</a></aside>
    @endif
</section>
