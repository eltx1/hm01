@extends('layouts.admin')
@section('title', 'Earnings & Payments')
@section('heading', 'Earnings & Payments')
@section('content')
@include('publisher.finance._tabs')

<section class="hero">
    <div>
        <p class="eyebrow">{{ $publisher->display_name }}</p>
        <h2>Your financial position, without estimated/finalized mixing</h2>
        <p>Every currency is shown separately. Estimated reporting can change; finalized earnings and statement balances are the accounting record.</p>
    </div>
    <span class="pill">Payment profile: {{ $profile?->verification_status?->value ?? 'INCOMPLETE' }}</span>
</section>

<article>
    <p class="eyebrow">Action Center</p>
    <h2>What you need to do</h2>
    @foreach($actions as $action)
        <div class="event"><strong>{{ $action['label'] }}</strong><span class="pill">{{ $action['code'] }}</span></div>
    @endforeach
</article>

@foreach($currencies as $currency)
    <section class="workspace-section">
        <div class="workspace-heading">
            <div><p class="eyebrow">{{ $currency['currency'] }}</p><h2>{{ $currency['current_period'] }} financial position</h2></div>
            <span class="pill">{{ $currency['readiness']['label'] }}</span>
        </div>
        <section class="metric-grid">
            @foreach([
                ['Estimated earnings', $currency['estimated_earnings_minor'], 'Not finalized'],
                ['Finalized earnings', $currency['finalized_earnings_minor'], 'Current period finalized rows'],
                ['Current payable', $currency['current_payable_minor'], 'Finalized statement liability'],
                ['Below threshold', $currency['below_threshold_minor'], 'Not yet payable'],
                ['Carry-forward', $currency['carry_forward_minor'], 'Remaining statement balance'],
                ['Pending payout', $currency['pending_payout_minor'], 'Created or approved'],
                ['Scheduled payout', $currency['scheduled_payout_minor'], 'Has a scheduled date'],
                ['Paid', $currency['paid_minor'], 'Settled amount only'],
            ] as [$label, $minor, $note])
                <article><p class="eyebrow">{{ $label }}</p><strong class="metric-small">{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $minor) }}</strong><span class="table-note">{{ $note }}</span></article>
            @endforeach
        </section>
        <article>
            <div class="summary-grid">
                <div><strong>Payment threshold</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['payment_threshold_minor']) }}</span></div>
                <div><strong>Opening carry-forward</strong><span>{{ $currency['currency'] }} {{ \App\Support\Money::formatMinor((int) $currency['opening_carry_forward_minor']) }}</span></div>
                <div><strong>Current period state</strong><span>{{ $currency['current_period_status'] }}</span></div>
                <div><strong>Last finalized period</strong><span>{{ $currency['last_finalized_period'] ?: 'No finalized statement' }}</span></div>
            </div>
        </article>
    </section>
@endforeach
@endsection
