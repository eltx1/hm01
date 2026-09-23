@extends('layouts.admin')
@section('title', 'Reports & Earnings')
@section('heading', 'Reports & Earnings')
@section('content')
@include('publisher.finance._tabs')
@php
    $canonicalCurrency = strtoupper((string) config('reporting.canonical_currency', 'USD'));
    $primary = $currencies->firstWhere('currency', $canonicalCurrency);
    $legacyCurrencies = $currencies->where('currency', '!=', $canonicalCurrency);
@endphp

<section class="hero">
    <div>
        <p class="eyebrow">{{ $publisher->display_name }}</p>
        <h2>Your performance and earnings in one place</h2>
        <p>Horus reports new ad revenue in <strong>{{ $canonicalCurrency }}</strong>, regardless of the base currency of the connected Google Ad Manager network. Today is estimated; finalized amounts become the accounting record.</p>
    </div>
    <x-status-badge :status="$profile?->verification_status ?? 'INCOMPLETE'" />
</section>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">What happens next</p><h2>Your earnings flow</h2></div></div>
    <div class="progress-strip">
        <div class="progress-step"><strong>1 · Today</strong><span class="muted">Live estimate updates as reporting arrives.</span></div>
        <div class="progress-step"><strong>2 · Finalized</strong><span class="muted">Completed reporting becomes your accounting record.</span></div>
        <div class="progress-step"><strong>3 · Payout</strong><span class="muted">Finalized balance moves through statement and payment status.</span></div>
    </div>
</article>

@if($actions !== [])
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Action Center</p><h2>What you need to do</h2></div></div>
    @foreach($actions as $action)
        <div class="compact-row"><div><strong>{{ $action['label'] }}</strong></div><span class="pill">{{ str($action['code'])->replace('_', ' ')->headline() }}</span></div>
    @endforeach
</article>
@endif

@if($primary)
<section class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">{{ $canonicalCurrency }} · Current reporting</p><h2>Today so far</h2></div>
        <span class="pill">{{ $primary['today_available'] ? 'ESTIMATED' : 'WAITING FOR DATA' }}</span>
    </div>
    @if($primary['today_available'])
        <section class="metric-grid">
            <article><p class="eyebrow">Impressions</p><strong class="metric">{{ number_format((int) $primary['today_impressions']) }}</strong><span class="table-note">Today</span></article>
            <article><p class="eyebrow">Clicks</p><strong class="metric">{{ number_format((int) $primary['today_clicks']) }}</strong><span class="table-note">Today</span></article>
            <article><p class="eyebrow">Estimated earnings</p><strong class="metric">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['today_estimated_earnings_minor']) }}</strong><span class="table-note">Your contractual share</span></article>
        </section>
        <p class="muted">Today's numbers can change before finalization.@if($primary['today_updated_at']) Last ledger update: {{ $primary['today_updated_at']->format('Y-m-d H:i:s') }}.@endif</p>
    @else
        <x-empty-state title="Today's report has not arrived yet" description="Nothing is wrong. Horus will show today's estimate automatically when the reporting source returns data." />
    @endif
</section>

<div class="dashboard-section-heading"><div><p class="eyebrow">This month</p><h2>Earnings &amp; payment position</h2></div></div>
<section class="metric-grid">
    @foreach([
        ['Estimated earnings', $primary['estimated_earnings_minor'], 'Not finalized yet'],
        ['Finalized earnings', $primary['finalized_earnings_minor'], 'Accounting record'],
        ['Current payable', $primary['current_payable_minor'], 'Finalized and payable'],
        ['Pending payout', $primary['pending_payout_minor'], 'Created or approved'],
        ['Paid', $primary['paid_minor'], 'Settled amount'],
    ] as [$label, $minor, $note])
        <article><p class="eyebrow">{{ $label }}</p><strong class="metric-small money">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $minor) }}</strong><span class="table-note">{{ $note }}</span></article>
    @endforeach
</section>

<article class="workspace-section">
    <div class="summary-grid">
        <div><strong>Payment threshold</strong><span class="money">{{ $canonicalCurrency }} {{ \App\Support\Money::formatMinor((int) $primary['payment_threshold_minor']) }}</span></div>
        <div><strong>Payment readiness</strong><span>{{ $primary['readiness']['label'] }}</span></div>
        <div><strong>Current period</strong><x-status-badge :status="$primary['current_period_status']" /></div>
        <div><strong>Last finalized period</strong><span>{{ $primary['last_finalized_period'] ?: 'No finalized statement yet' }}</span></div>
    </div>
    <div class="button-row">
        <a class="hm-button-primary button-link" href="{{ route('publisher.finance.statements.index') }}">View statements</a>
        <a class="hm-button-secondary button-link" href="{{ route('publisher.finance.payouts.index') }}">View payouts</a>
        <a class="text-link" href="{{ route('publisher.finance.payment-method.edit') }}">Payment method</a>
    </div>
</article>
@else
    <x-empty-state title="No USD earnings data yet" description="New GAM reporting is standardized to USD. Your first estimate will appear here automatically when reporting data is imported." />
@endif

@if($legacyCurrencies->isNotEmpty())
<details class="workspace-section secondary-workflow">
    <summary>Historical non-USD accounting ({{ $legacyCurrencies->count() }})</summary>
    <p class="muted">These records are preserved for accounting history. New GAM revenue is not added to these currencies.</p>
    @foreach($legacyCurrencies as $currency)
        <div class="compact-row">
            <div><strong>{{ $currency['currency'] }} historical record</strong><p>Last finalized period: {{ $currency['last_finalized_period'] ?: '—' }}</p></div>
            <span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['statement_balance_due_minor']) }} due</span>
        </div>
    @endforeach
</details>
@endif
@endsection
