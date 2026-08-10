@extends('layouts.admin')
@section('title', 'Payout History')
@section('heading', 'Payout History')
@section('content')
@include('publisher.finance._tabs')
<section class="hero"><div><p class="eyebrow">Settlement evidence</p><h2>Payout history</h2><p>A created or scheduled payout is not shown as paid. Paid amounts use recorded settlement values only.</p></div></section>
<div class="table-wrap">
    <table>
        <thead><tr><th>Payment</th><th>Statement / period</th><th>Method</th><th>Scheduled</th><th>Requested</th><th>Settled</th><th>Status</th><th>Paid</th><th>Reference / explanation</th></tr></thead>
        <tbody>
        @forelse($payments as $payment)
            <tr>
                <td><strong>{{ $payment->payment_number }}</strong></td>
                <td><a href="{{ route('publisher.finance.statements.show', $payment->statement) }}">{{ $payment->statement->statement_number }}</a><span class="table-note">{{ $payment->statement->period->period_key }}</span></td>
                <td>{{ $payment->payment_method ?: '—' }}</td>
                <td>{{ $payment->scheduled_on?->toDateString() ?: '—' }}</td>
                <td>{{ $payment->currency }} {{ \App\Support\Money::formatMinor((int) $payment->amount_minor) }}</td>
                <td>{{ $payment->currency }} {{ \App\Support\Money::formatMinor((int) $payment->settled_amount_minor) }}</td>
                <td><span class="pill">{{ $payment->status->value }}</span>@if((int) $payment->settled_amount_minor > 0 && (int) $payment->settled_amount_minor < (int) $payment->amount_minor)<span class="table-note">Partial settlement</span>@endif</td>
                <td>{{ $payment->paid_at?->toDateString() ?: '—' }}</td>
                <td>@forelse($payment->settlements as $settlement)<span class="table-note">{{ $settlement->settlement_reference }} · {{ $payment->currency }} {{ \App\Support\Money::formatMinor((int) $settlement->amount_minor) }}</span>@empty No settlement reference @endforelse @if($payment->publisher_message)<span class="table-note">{{ $payment->publisher_message }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="9">No payouts have been created.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
