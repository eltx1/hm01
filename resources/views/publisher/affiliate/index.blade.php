@extends('layouts.admin')
@section('title', 'Affiliate referrals')
@section('heading', 'Affiliate referrals')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Publisher affiliate program</p>
        <h2>Refer Publishers and earn from their finalized net Publisher earnings</h2>
        <p>Your commission is calculated only from each referred Publisher's finalized Publisher earnings. It never reduces their payout and does not earn commission on other affiliate income.</p>
    </div>
    <span class="pill">{{ number_format($effectiveRateBp / 100, 2) }}%</span>
</section>

<section class="metric-grid">
    <article><p class="eyebrow">Referred Publishers</p><strong class="metric-small">{{ number_format($referralsCount) }}</strong></article>
    <article>
        <p class="eyebrow">Affiliate earnings</p>
        @forelse($earnedByCurrency as $total)
            <strong class="metric-small money">{{ $total->currency }} {{ \App\Support\Money::formatMinor((int) $total->total_minor) }}</strong>
        @empty
            <strong class="metric-small money">—</strong>
        @endforelse
        <span class="table-note">Finalized totals are kept separate by currency</span>
    </article>
    <article><p class="eyebrow">Current commission</p><strong class="metric-small">{{ number_format($effectiveRateBp / 100, 2) }}%</strong><span class="table-note">Applied to future finalized Publisher earnings</span></article>
</section>

<article>
    <p class="eyebrow">Your referral link</p>
    <h2>Share this link with Publishers you invite</h2>
    <div class="form-stack">
        <input class="hm-input" value="{{ $referralUrl }}" readonly onclick="this.select()" aria-label="Publisher referral link">
        <p class="table-note">Referral code: <strong>{{ $publisher->referral_code }}</strong></p>
    </div>
</article>

<section class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Commission ledger</p><h2>Finalized affiliate earnings</h2></div>
    </div>
    @if($commissions->count())
        <div class="table-wrap">
            <table>
                <thead><tr><th>Period</th><th>Referred Publisher</th><th>Basis</th><th>Rate</th><th>Commission</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($commissions as $commission)
                    <tr>
                        <td>{{ $commission->period?->period_key ?? '—' }}</td>
                        <td>{{ $commission->referredPublisher?->display_name ?? 'Publisher' }}</td>
                        <td class="money">{{ $commission->currency }} {{ \App\Support\Money::formatMinor((int) $commission->basis_minor) }}</td>
                        <td>{{ number_format($commission->commission_rate_bp / 100, 2) }}%</td>
                        <td class="money">{{ $commission->currency }} {{ \App\Support\Money::formatMinor((int) $commission->commission_minor) }}</td>
                        <td><x-status-badge :status="$commission->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $commissions->links() }}
    @else
        <x-empty-state title="No affiliate earnings yet" description="When a referred Publisher has finalized Publisher earnings, the commission will be recorded here and included in your statement balance." />
    @endif
</section>
@endsection
