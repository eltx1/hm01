@extends('layouts.admin')
@section('title', 'Reports & earnings')
@section('heading', 'Reports & earnings')
@section('content')
@include('publisher.finance._tabs')

@php
    $canonicalCurrency = strtoupper((string) config('reporting.canonical_currency', 'USD'));
    $primary = $currencies->firstWhere('currency', $canonicalCurrency);
    $legacyCurrencies = $currencies->where('currency', '!=', $canonicalCurrency)->values();
    $financeActions = collect($actions)->reject(fn ($action) => ($action['code'] ?? '') === 'NONE')->values();
@endphp

<section class="hero">
    <div>
        <p class="eyebrow">{{ $publisher->display_name }}</p>
        <h2>See what you earned. Know what comes next.</h2>
        <p>Horus standardizes Ad Manager reporting to <strong>{{ $canonicalCurrency }}</strong>. Today is estimated; finalized earnings and statements are the accounting record.</p>
        <div class="status-row">
            <span class="pill">Reporting currency · {{ $canonicalCurrency }}</span>
            <x-status-badge :status="$profile?->verification_status ?? 'INCOMPLETE'" />
        </div>
    </div>
</section>

@include('publisher.finance._performance')

@if($financeActions->isNotEmpty())
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Needs your attention</p><h2>Finance actions</h2></div><span class="pill">{{ $financeActions->count() }}</span></div>
    <div class="compact-list">
        @foreach($financeActions as $action)
            <div class="compact-row"><div><strong>{{ $action['label'] }}</strong></div>@if(!empty($action['href']))<a class="hm-button-secondary" href="{{ $action['href'] }}">{{ $action['link_label'] ?? 'View details' }}</a>@endif</div>
        @endforeach
    </div>
</article>
@endif

@if($primary)
<section class="workspace-section publisher-reporting-primary">
    <div class="workspace-heading">
        <div><p class="eyebrow">Today so far</p><h2>Live estimate</h2></div>
        <span class="pill">{{ $primary['today_available'] ? 'ESTIMATED' : 'WAITING FOR DATA' }}</span>
    </div>
    @if($primary['today_available'])
        <section class="metric-grid">
            <article><p class="eyebrow">Estimated earnings</p><strong class="metric">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['today_estimated_earnings_minor']) }}</strong><span class="muted">Your contractual share</span></article>
            <article><p class="eyebrow">Impressions</p><strong class="metric">{{ number_format((int) $primary['today_impressions']) }}</strong><span class="muted">Today’s source-local reporting day</span></article>
            <article><p class="eyebrow">Clicks</p><strong class="metric">{{ number_format((int) $primary['today_clicks']) }}</strong><span class="muted">Today’s source-local reporting day</span></article>
        </section>
        <p class="muted">Today can change before finalization.@if($primary['today_updated_at']) Last ledger update: {{ $primary['today_updated_at']->format('Y-m-d H:i:s') }}.@endif</p>
    @else
        <div class="calm-state"><h3>Today’s report has not arrived yet</h3><p class="muted">This does not change finalized accounting. Horus will show the estimate here when the current-day report is imported.</p></div>
    @endif
</section>

<section class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Finance · separate from your selected report dates</p><h2>Your statement and payouts</h2></div><span class="pill">{{ $primary['readiness']['label'] }}</span></div>
    <div class="report-metrics">
        <article><p class="eyebrow">Latest statement balance</p><strong class="metric">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['statement_balance_due_minor']) }}</strong><span class="muted">{{ $primary['last_finalized_period'] ?: 'No finalized statement yet' }} · includes carry-forward</span>@if($primary['latest_statement_id'])<a class="section-anchor" href="{{ route('publisher.finance.statements.show', $primary['latest_statement_id']) }}">View statement →</a>@endif</article>
        <article><p class="eyebrow">Pending payout</p><strong class="metric">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['pending_payout_minor']) }}</strong><span class="muted">Includes scheduled and held amounts</span></article>
        <article><p class="eyebrow">Paid to date</p><strong class="metric">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['paid_minor']) }}</strong><span class="muted">Settled payments across all periods</span></article>
        <article><p class="eyebrow">Payment threshold</p><strong class="metric">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['payment_threshold_minor']) }}</strong><span class="muted">Subject to invoice and Finance review</span></article>
    </div>
    <p class="muted">Reports track ad performance. Monthly statements include approved adjustments, referral earnings and carry-forward. An uploaded invoice must be reviewed before payout; money is only marked paid after settlement.</p>
</section>

<section class="split-grid">
    <article>
        <div class="workspace-heading"><div><p class="eyebrow">Payouts</p><h2>What happens next</h2></div><a class="section-anchor" href="{{ route('publisher.finance.payouts.index') }}">Payout history →</a></div>
        <div class="compact-row"><div><strong>Pending payout</strong><p>All unsettled payouts, including scheduled and held</p></div><strong class="money">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['pending_payout_minor']) }}</strong></div>
        <div class="compact-row"><div><strong>Scheduled payout</strong><p>Part of pending payout, not an additional balance</p></div><strong class="money">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['scheduled_payout_minor']) }}</strong></div>
        <a class="hm-button-secondary button-link" href="{{ route('publisher.finance.payment-method.edit') }}">Payment details</a>
    </article>
    <article>
        <div class="workspace-heading"><div><p class="eyebrow">Accounting</p><h2>Statement details</h2></div><a class="section-anchor" href="{{ route('publisher.finance.statements.index') }}">Statements →</a></div>
        <div class="summary-grid">
            <div><strong>Payment threshold</strong><span class="money">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['payment_threshold_minor']) }}</span></div>
            <div><strong>Carry-forward</strong><span class="money">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['carry_forward_minor']) }}</span></div>
            <div><strong>Affiliate earnings</strong><span class="money">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['affiliate_earnings_minor']) }}</span></div>
            <div><strong>Last finalized period</strong><span>{{ $primary['last_finalized_period'] ?: 'No finalized statement yet' }}</span></div>
        </div>
    </article>
</section>
@else
    <x-empty-state title="No USD reporting data yet" description="Your reports and earnings will appear here after Horus receives reporting data for your account." />
@endif

@if($legacyCurrencies->isNotEmpty())
<details class="workspace-section legacy-finance-records">
    <summary>Older accounting records in other currencies ({{ $legacyCurrencies->count() }})</summary>
    <p class="muted">These records are preserved for accounting history and are not mixed into the {{ $canonicalCurrency }} dashboard.</p>
    @foreach($legacyCurrencies as $currency)
        <div class="compact-row">
            <div><strong>{{ $currency['currency'] }} records</strong><p>Finalized {{ \App\Support\Money::formatMinor((int) $currency['finalized_earnings_minor']) }} · Paid {{ \App\Support\Money::formatMinor((int) $currency['paid_minor']) }}</p></div>
            <span class="pill">LEGACY / OTHER CURRENCY</span>
        </div>
    @endforeach
</details>
@endif
@endsection
