@extends('layouts.admin')
@section('title', 'Reports & Earnings')
@section('heading', 'Reports & Earnings')
@section('content')
@include('publisher.finance._tabs')
@php
    $canonicalCurrency = strtoupper((string) config('reporting.canonical_currency', 'USD'));
    $canonical = collect($currencies)->firstWhere('currency', $canonicalCurrency);
    $historical = collect($currencies)
        ->reject(fn ($item) => $item['currency'] === $canonicalCurrency)
        ->filter(fn ($item) =>
            (int) $item['estimated_earnings_minor']
            + (int) $item['finalized_earnings_minor']
            + (int) $item['affiliate_earnings_minor']
            + (int) $item['current_payable_minor']
            + (int) $item['below_threshold_minor']
            + (int) $item['carry_forward_minor']
            + (int) $item['pending_payout_minor']
            + (int) $item['scheduled_payout_minor']
            + (int) $item['paid_minor']
            + (int) $item['opening_carry_forward_minor'] !== 0
            || filled($item['last_finalized_period'])
        )
        ->values();
@endphp

<section class="hero">
    <div>
        <p class="eyebrow">{{ $publisher->display_name }}</p>
        <h2>Your reporting and earnings in one place.</h2>
        <p>Horus reporting is standardized to USD. Today’s numbers are estimates; finalized earnings and statements are the accounting record.</p>
        <div class="status-row">
            <a class="hm-button-primary button-link" href="#current-report">View current report</a>
            <a class="hm-button-secondary button-link" href="{{ route('publisher.finance.statements.index') }}">Statements</a>
            <a class="hm-button-secondary button-link" href="{{ route('publisher.finance.payouts.index') }}">Payouts</a>
        </div>
    </div>
    <span class="canonical-currency-note">Canonical reporting · {{ $canonicalCurrency }}</span>
</section>

@if(collect($actions)->where('code', '!=', 'NONE')->isNotEmpty())
<article class="workspace-section">
    <p class="eyebrow">Needs your attention</p>
    <h2>Next finance actions</h2>
    @foreach($actions as $action)
        @if($action['code'] !== 'NONE')
            <div class="event"><strong>{{ $action['label'] }}</strong><span class="pill">{{ str($action['code'])->replace('_', ' ')->headline() }}</span></div>
        @endif
    @endforeach
</article>
@endif

@if($canonical)
<section id="current-report" class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">{{ $canonicalCurrency }} · Current reporting</p><h2>{{ $canonical['current_period'] }} performance &amp; earnings</h2></div>
        <span class="pill">{{ $canonical['readiness']['label'] }}</span>
    </div>

    <section class="dashboard-primary">
        <article>
            <p class="eyebrow">Today so far</p>
            <strong class="metric">{{ $canonicalCurrency }} {{ AppSupportMoney::formatMinor((int) $canonical['today_estimated_earnings_minor']) }}</strong>
            <span class="muted">{{ $canonical['today_available'] ? 'Estimated publisher earnings' : 'Waiting for today’s data' }}</span>
        </article>
        <article>
            <p class="eyebrow">This month · finalized</p>
            <strong class="metric">{{ $canonicalCurrency }} {{ AppSupportMoney::formatMinor((int) $canonical['finalized_earnings_minor']) }}</strong>
            <span class="muted">Finalized publisher earnings</span>
        </article>
        <article>
            <p class="eyebrow">Current payable</p>
            <strong class="metric">{{ $canonicalCurrency }} {{ AppSupportMoney::formatMinor((int) $canonical['current_payable_minor']) }}</strong>
            <span class="muted">Finalized statement liability</span>
        </article>
        <article>
            <p class="eyebrow">Paid</p>
            <strong class="metric">{{ $canonicalCurrency }} {{ AppSupportMoney::formatMinor((int) $canonical['paid_minor']) }}</strong>
            <span class="muted">Settled payouts</span>
        </article>
    </section>

    <article class="soft-panel">
        <div class="workspace-heading">
            <div><p class="eyebrow">Today so far</p><h3>Live estimate</h3></div>
            <x-status-badge :status="$canonical['today_available'] ? 'ESTIMATED' : 'WAITING'" />
        </div>
        @if($canonical['today_available'])
            <div class="health-grid">
                <div><span class="muted">Impressions</span><strong class="metric-small">{{ number_format((int) $canonical['today_impressions']) }}</strong></div>
                <div><span class="muted">Clicks</span><strong class="metric-small">{{ number_format((int) $canonical['today_clicks']) }}</strong></div>
                <div><span class="muted">Estimated earnings</span><strong class="metric-small">{{ $canonicalCurrency }} {{ AppSupportMoney::formatMinor((int) $canonical['today_estimated_earnings_minor']) }}</strong></div>
            </div>
            <p class="muted">Today so far can change before finalization. Only your contractual publisher earnings are shown. @if($canonical['today_updated_at']) Last ledger update: {{ $canonical['today_updated_at']->format('Y-m-d H:i:s') }}.@endif</p>
        @else
            <p class="muted">Today’s Google reporting data has not arrived yet. This does not change finalized accounting.</p>
        @endif
    </article>

    <div class="dashboard-section-title"><p class="eyebrow">More detail</p><h2>Balances and payout status</h2></div>
    <section class="metric-grid">
        @foreach([
            ['Estimated this month', $canonical['estimated_earnings_minor'], 'Not finalized'],
            ['Affiliate earnings', $canonical['affiliate_earnings_minor'], 'Latest finalized statement'],
            ['Below threshold', $canonical['below_threshold_minor'], 'Not yet payable'],
            ['Carry-forward', $canonical['carry_forward_minor'], 'Remaining statement balance'],
            ['Pending payout', $canonical['pending_payout_minor'], 'Created or approved'],
            ['Scheduled payout', $canonical['scheduled_payout_minor'], 'Has a scheduled date'],
        ] as [$label, $minor, $note])
            <article><p class="eyebrow">{{ $label }}</p><strong class="metric-small money">{{ $canonicalCurrency }} {{ AppSupportMoney::formatMinor((int) $minor) }}</strong><span class="table-note">{{ $note }}</span></article>
        @endforeach
    </section>
    <article class="soft-panel">
        <div class="summary-grid">
            <div><strong>Payment threshold</strong><span>{{ $canonicalCurrency }} {{ AppSupportMoney::formatMinor((int) $canonical['payment_threshold_minor']) }}</span></div>
            <div><strong>Opening carry-forward</strong><span>{{ $canonicalCurrency }} {{ AppSupportMoney::formatMinor((int) $canonical['opening_carry_forward_minor']) }}</span></div>
            <div><strong>Current period state</strong><x-status-badge :status="$canonical['current_period_status']" /></div>
            <div><strong>Last finalized period</strong><span>{{ $canonical['last_finalized_period'] ?: 'No finalized statement' }}</span></div>
        </div>
    </article>
</section>
@else
<x-empty-state title="No USD earnings data yet" description="Current reporting will appear here after the connected reporting source imports data in USD." />
@endif

@if($historical->isNotEmpty())
<details class="workspace-section soft-panel">
    <summary><strong>Historical non-USD balances</strong> · {{ $historical->pluck('currency')->join(', ') }}</summary>
    <p class="muted">These are retained historical records. Current GAM reporting is standardized to USD and these currencies are not combined with current USD totals.</p>
    @foreach($historical as $currency)
        <div class="compact-row">
            <div><strong>{{ $currency['currency'] }}</strong><p>Historical finalized earnings and statement balances</p></div>
            <span>{{ $currency['currency'] }} {{ AppSupportMoney::formatMinor((int) $currency['finalized_earnings_minor']) }}</span>
        </div>
    @endforeach
</details>
@endif
@endsection
