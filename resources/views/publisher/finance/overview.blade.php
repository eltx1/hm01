@extends('layouts.admin')
@section('title', 'Earnings')
@section('heading', 'Earnings')
@section('content')
@include('publisher.finance._tabs')

@php($canonicalCurrency = strtoupper((string) config('reporting.dashboard_currency', 'USD')))

<section class="hero dashboard-hero">
    <div>
        <p class="eyebrow">{{ $publisher->display_name }}</p>
        <h2>Your earnings, without accounting jargon.</h2>
        <p>Use this page for today’s estimate, month-to-date earnings, finalized statements and payout status. Horus reporting is standardized to US Dollar (USD).</p>
    </div>
    <div class="hero-stat"><span>Primary currency</span><strong>{{ $canonicalCurrency }}</strong><small>US Dollar</small></div>
</section>

@if($actions !== [] && ! (count($actions) === 1 && ($actions[0]['code'] ?? null) === 'NONE'))
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Needs attention</p><h2>Payment actions</h2></div></div>
    <div class="compact-list">
        @foreach($actions as $action)
            <div class="compact-row"><div><strong>{{ $action['label'] }}</strong><p>{{ str($action['code'])->replace('_', ' ')->headline() }}</p></div><x-status-badge status="PENDING" /></div>
        @endforeach
    </div>
</article>
@endif

@forelse($currencies as $currency)
    @php($isCanonical = $currency['currency'] === $canonicalCurrency)
    @if($isCanonical)
        <section class="workspace-section finance-primary-section">
            <div class="workspace-heading">
                <div><p class="eyebrow">US Dollar · primary reporting</p><h2>{{ $currency['current_period'] }} earnings</h2></div>
                <span class="pill">{{ $currency['readiness']['label'] }}</span>
            </div>

            <article class="today-card">
                <div class="workspace-heading">
                    <div><p class="eyebrow">Today so far</p><h3>Estimated earnings</h3></div>
                    <span class="pill">{{ $currency['today_available'] ? 'ESTIMATED' : 'WAITING' }}</span>
                </div>
                @if($currency['today_available'])
                    <div class="dashboard-metrics dashboard-metrics-compact">
                        <div><span>Estimated earnings</span><strong>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['today_estimated_earnings_minor']) }}</strong></div>
                        <div><span>Impressions</span><strong>{{ number_format((int) $currency['today_impressions']) }}</strong></div>
                        <div><span>Clicks</span><strong>{{ number_format((int) $currency['today_clicks']) }}</strong></div>
                    </div>
                    <p class="muted">Today is estimated and can change before Google finalizes the reporting day. Your contractual share only is shown here.@if($currency['today_updated_at']) Last imported {{ $currency['today_updated_at']->diffForHumans() }}.@endif</p>
                @else
                    <p class="muted">Today’s first report has not arrived yet. This updates automatically.</p>
                @endif
            </article>

            <section class="dashboard-metrics">
                <article class="dashboard-metric-card is-primary"><p class="eyebrow">Estimated this month</p><strong class="metric">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['estimated_earnings_minor']) }}</strong><span class="muted">Not finalized yet</span></article>
                <article class="dashboard-metric-card"><p class="eyebrow">Finalized this month</p><strong class="metric">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['finalized_earnings_minor']) }}</strong><span class="muted">Accounting record</span></article>
                <article class="dashboard-metric-card"><p class="eyebrow">Current balance</p><strong class="metric">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['statement_balance_due_minor']) }}</strong><span class="muted">Latest finalized statement</span></article>
                <article class="dashboard-metric-card"><p class="eyebrow">Paid</p><strong class="metric">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['paid_minor']) }}</strong><span class="muted">Settled payouts</span></article>
            </section>

            <details class="finance-details">
                <summary>More financial details</summary>
                <div class="summary-grid">
                    <div><strong>Affiliate earnings</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['affiliate_earnings_minor']) }}</span></div>
                    <div><strong>Payment threshold</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['payment_threshold_minor']) }}</span></div>
                    <div><strong>Below threshold</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['below_threshold_minor']) }}</span></div>
                    <div><strong>Carry-forward</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['carry_forward_minor']) }}</span></div>
                    <div><strong>Pending payout</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['pending_payout_minor']) }}</span></div>
                    <div><strong>Scheduled payout</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['scheduled_payout_minor']) }}</span></div>
                    <div><strong>Current period</strong><span>{{ $currency['current_period_status'] }}</span></div>
                    <div><strong>Last statement</strong><span>{{ $currency['last_finalized_period'] ?: 'Not finalized yet' }}</span></div>
                </div>
            </details>
        </section>
    @else
        <details class="workspace-section legacy-finance-section">
            <summary><strong>Historical {{ $currency['currency'] }} activity</strong> · preserved for audit and prior payouts</summary>
            <p class="muted">This currency is historical or belongs to a non-canonical financial record. New Google Ad Manager website reporting is requested in USD.</p>
            <div class="summary-grid">
                <div><strong>Finalized earnings</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['finalized_earnings_minor']) }}</span></div>
                <div><strong>Balance</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['statement_balance_due_minor']) }}</span></div>
                <div><strong>Paid</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['paid_minor']) }}</span></div>
                <div><strong>Last statement</strong><span>{{ $currency['last_finalized_period'] ?: 'None' }}</span></div>
            </div>
        </details>
    @endif
@empty
    <x-empty-state title="No earnings data yet" description="Earnings appear here automatically after your first reporting import." />
@endforelse
@endsection
