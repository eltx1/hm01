@extends('layouts.admin')
@section('title', 'Finance Operations')
@section('heading', 'Finance Operations')
@section('content')
@include('admin.finance._tabs')
<section class="hero"><div><p class="eyebrow">Monthly Publisher finance</p><h2>Liability, readiness, payouts, and reconciliation</h2><p>Operational totals remain separated by currency. A payout record is never treated as money moved until an immutable settlement is recorded.</p></div></section>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Currency boundaries</p><h2>Publisher liability and payout state</h2></div></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Currency</th><th>Outstanding liability</th><th>Ready for payout</th><th>Below threshold</th><th>Awaiting invoice</th><th>Pending approval</th><th>Approved</th><th>Scheduled</th><th>Paid this month</th><th>Partial remainder</th><th>Failed / held</th></tr></thead>
        <tbody>
        @forelse($currency_totals as $currency => $totals)
            <tr>
                <th>{{ $currency }}</th>
                @foreach(['outstanding_liability_minor','ready_for_payout_minor','below_threshold_minor','awaiting_invoice_minor','pending_approval_minor','approved_minor','scheduled_minor','paid_this_month_minor','partial_remaining_minor','failed_or_held_minor'] as $field)
                    <td>{{ \App\Support\Money::formatMinor((int) $totals[$field]) }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="11" class="muted">No financial balances exist yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</article>

<section class="metric-grid workspace-section">
    @foreach([
        ['Profiles incomplete', $counts['profiles_incomplete']],
        ['Awaiting verification', $counts['profiles_pending']],
        ['Changed after verification', $counts['profiles_needs_update']],
        ['Rejected profiles', $counts['profiles_rejected']],
        ['Partial payouts', $counts['partial_payments']],
        ['Failed payouts', $counts['failed_payouts']],
        ['Held payouts', $counts['held_payouts']],
        ['Open periods', $counts['open_periods']],
        ['Periods ready', $counts['periods_ready']],
        ['Periods blocked', $counts['periods_blocked']],
        ['Reconciliation discrepancies', $counts['reconciliation_discrepancies']],
    ] as [$label, $value])
        <article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ number_format($value) }}</strong></article>
    @endforeach
</section>

<article class="workspace-section"><div class="workspace-heading"><div><p class="eyebrow">Close readiness</p><h2>Recent financial periods</h2></div><a class="text-link" href="{{ route('admin.finance.periods.index') }}">Open workbench</a></div>
    @forelse($periods->take(6) as $row)
        <div class="compact-row"><div><strong>{{ $row['period']->period_key }} · {{ $row['period']->currency }}</strong><p>{{ collect($row['readiness']['blockers'] ?? [])->pluck('message')->join(' · ') ?: 'All readiness gates pass.' }}</p></div><x-status-badge :status="$row['period']->isClosed() ? $row['period']->status : ($row['readiness']['ready'] ? 'READY' : 'BLOCKED')" /></div>
    @empty<p class="muted">No financial periods exist yet.</p>@endforelse
</article>
@endsection
