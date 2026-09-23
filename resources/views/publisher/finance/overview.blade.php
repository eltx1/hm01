@extends('layouts.admin')
@section('title', 'Earnings & Payments')
@section('heading', 'Earnings & Payments')
@section('content')
@include('publisher.finance._tabs')

<section class="hero">
    <div>
        <p class="eyebrow">{{ $publisher->display_name }}</p>
        <h2>Earnings, statements and payouts in one place</h2>
        <p>Horus reporting is standardized to USD. Today's figures are estimates; finalized statements remain the accounting record. Historical payout obligations in another currency, if any, stay visible separately.</p>
    </div>
    <x-status-badge :status="$profile?->verification_status ?? 'INCOMPLETE'" />
</section>

<article>
    <p class="eyebrow">Start here</p>
    <h2>What needs your attention</h2>
    @forelse($actions as $action)
        <div class="event"><strong>{{ $action['label'] }}</strong><span class="pill">{{ str($action['code'])->replace('_', ' ')->headline() }}</span></div>
    @empty
        <x-empty-state title="No finance actions required" description="Your current payment and statement setup has no outstanding Publisher action." />
    @endforelse
</article>

@forelse($currencies as $currency)
    <section class="workspace-section">
        <div class="workspace-heading">
            <div><p class="eyebrow">{{ $currency['currency'] }}</p><h2>{{ $currency['current_period'] }} financial position</h2></div>
            <span class="pill">{{ $currency['readiness']['label'] }}</span>
        </div>
        <article class="domain-card">
            <div class="workspace-heading">
                <div><p class="eyebrow">Today so far</p><h3>Publisher-safe live estimate</h3></div>
                <span class="pill">{{ $currency['today_available'] ? 'ESTIMATED' : 'WAITING' }}</span>
            </div>
            @if($currency['today_available'])
                <section class="metric-grid">
                    <article><p class="eyebrow">Impressions</p><strong class="metric-small">{{ number_format((int) $currency['today_impressions']) }}</strong><span class="table-note">Source-local reporting day</span></article>
                    <article><p class="eyebrow">Clicks</p><strong class="metric-small">{{ number_format((int) $currency['today_clicks']) }}</strong><span class="table-note">Source-local reporting day</span></article>
                    <article><p class="eyebrow">Estimated earnings</p><strong class="metric-small money">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['today_estimated_earnings_minor']) }}</strong><span class="table-note">Your contractual share only</span></article>
                </section>
                <p class="muted">Today so far is estimated and may change before finalization. Internal platform economics are not Publisher-visible. @if($currency['today_updated_at']) Last ledger update: {{ $currency['today_updated_at']->format('Y-m-d H:i:s') }}.@endif</p>
            @else
                <p class="muted">No current-day estimated rows are available yet for this currency. Finalized accounting remains unchanged.</p>
            @endif
        </article>
        <section class="metric-grid">
            @foreach([
                ['Estimated earnings', $currency['estimated_earnings_minor'], 'Not finalized'],
                ['Finalized earnings', $currency['finalized_earnings_minor'], 'Current period finalized ad earnings'],
                ['Affiliate earnings', $currency['affiliate_earnings_minor'], 'Latest finalized statement'],
                ['Current payable', $currency['current_payable_minor'], 'Finalized statement liability'],
                ['Below threshold', $currency['below_threshold_minor'], 'Not yet payable'],
                ['Carry-forward', $currency['carry_forward_minor'], 'Remaining statement balance'],
                ['Pending payout', $currency['pending_payout_minor'], 'Created or approved'],
                ['Scheduled payout', $currency['scheduled_payout_minor'], 'Has a scheduled date'],
                ['Paid', $currency['paid_minor'], 'Settled amount only'],
            ] as [$label, $minor, $note])
                <article><p class="eyebrow">{{ $label }}</p><strong class="metric-small money">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $minor) }}</strong><span class="table-note">{{ $note }}</span></article>
            @endforeach
        </section>
        <article>
            <div class="summary-grid">
                <div><strong>Payment threshold</strong><span class="money">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['payment_threshold_minor']) }}</span></div>
                <div><strong>Opening carry-forward</strong><span class="money">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['opening_carry_forward_minor']) }}</span></div>
                <div><strong>Current period state</strong><x-status-badge :status="$currency['current_period_status']" /></div>
                <div><strong>Last finalized period</strong><span>{{ $currency['last_finalized_period'] ?: 'No finalized statement' }}</span></div>
            </div>
        </article>
    </section>
@empty
    <x-empty-state title="No earnings data yet" description="Estimated and finalized earnings will appear here after reporting data is available for your Publisher account." />
@endforelse
@endsection