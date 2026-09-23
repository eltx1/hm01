@extends('layouts.admin')
@section('title', 'Earnings')
@section('heading', 'Earnings & Payments')
@section('content')
@include('publisher.finance._tabs')

<section class="hero">
    <div>
        <p class="eyebrow">{{ $publisher->display_name }}</p>
        <h2>Your earnings in one place</h2>
        <p>Start with today and this month. Finalized statements and payout details stay available below when you need them.</p>
    </div>
    <x-status-badge :status="$profile?->verification_status ?? 'INCOMPLETE'" />
</section>

@if(collect($actions)->where('code', '!=', 'NONE')->isNotEmpty())
<section class="action-center">
    <div class="workspace-heading">
        <div><p class="eyebrow">Needs your attention</p><h2>Finance actions</h2></div>
        <x-status-badge status="PENDING" />
    </div>
    <div class="compact-list">
        @foreach(collect($actions)->where('code', '!=', 'NONE') as $action)
            <div class="compact-row"><div><strong>{{ $action['label'] }}</strong></div><span class="pill">{{ str($action['code'])->replace('_', ' ')->headline() }}</span></div>
        @endforeach
    </div>
</section>
@endif

@forelse($currencies as $currency)
<section class="workspace-section earnings-overview" aria-labelledby="earnings-{{ strtolower($currency['currency']) }}">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">{{ $currency['currency'] }}</p>
            <h2 id="earnings-{{ strtolower($currency['currency']) }}">{{ $currency['current_period'] }} earnings</h2>
        </div>
        <span class="pill">{{ $currency['readiness']['label'] }}</span>
    </div>

    <div class="publisher-kpi-grid">
        <article class="publisher-kpi-card">
            <span>Today estimated</span>
            <strong>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['today_estimated_earnings_minor']) }}</strong>
            <small>{{ number_format((int) $currency['today_impressions']) }} impressions · {{ number_format((int) $currency['today_clicks']) }} clicks</small>
        </article>
        <article class="publisher-kpi-card">
            <span>This month estimated</span>
            <strong>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['estimated_earnings_minor']) }}</strong>
            <small>May change before finalization</small>
        </article>
        <article class="publisher-kpi-card">
            <span>This month finalized</span>
            <strong>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['finalized_earnings_minor']) }}</strong>
            <small>Accounting record</small>
        </article>
        <a class="publisher-kpi-card" href="{{ route('publisher.finance.statements.index') }}">
            <span>Balance due</span>
            <strong>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['statement_balance_due_minor']) }}</strong>
            <small>Open statements →</small>
        </a>
    </div>

    @if(!$currency['today_available'])
        <p class="muted">Today's Google reporting data has not arrived yet. Finalized accounting is unaffected.</p>
    @elseif($currency['today_updated_at'])
        <p class="muted">Today so far is estimated. Last ledger update: {{ $currency['today_updated_at']->format('Y-m-d H:i:s') }}.</p>
    @endif

    <details class="finance-details">
        <summary>More finance details</summary>
        <div class="metric-grid finance-detail-grid">
            @foreach([
                ['Current payable', $currency['current_payable_minor'], 'Finalized statement liability'],
                ['Below threshold', $currency['below_threshold_minor'], 'Not yet payable'],
                ['Carry-forward', $currency['carry_forward_minor'], 'Remaining statement balance'],
                ['Pending payout', $currency['pending_payout_minor'], 'Created or approved'],
                ['Scheduled payout', $currency['scheduled_payout_minor'], 'Has a scheduled date'],
                ['Paid', $currency['paid_minor'], 'Settled amount only'],
            ] as [$label, $minor, $note])
                <article>
                    <p class="eyebrow">{{ $label }}</p>
                    <strong class="metric-small money">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $minor) }}</strong>
                    <span class="table-note">{{ $note }}</span>
                </article>
            @endforeach
        </div>
        <div class="summary-grid">
            <div><strong>Payment threshold</strong><span class="money">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['payment_threshold_minor']) }}</span></div>
            <div><strong>Opening carry-forward</strong><span class="money">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['opening_carry_forward_minor']) }}</span></div>
            <div><strong>Current period</strong><x-status-badge :status="$currency['current_period_status']" /></div>
            <div><strong>Last finalized period</strong><span>{{ $currency['last_finalized_period'] ?: 'No finalized statement yet' }}</span></div>
        </div>
    </details>
</section>
@empty
    <x-empty-state title="No earnings data yet" description="Your earnings will appear here automatically after reporting data is available.">
        @if(auth()->user()->hasPermission('sites.view'))<a class="hm-button-primary" href="{{ route('publisher.sites.index') }}">Check websites</a>@endif
    </x-empty-state>
@endforelse
@endsection
