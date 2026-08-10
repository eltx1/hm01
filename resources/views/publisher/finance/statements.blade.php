@extends('layouts.admin')
@section('title', 'Publisher statements')
@section('heading', 'Publisher statements')
@section('content')
@include('publisher.finance._tabs')
<section class="hero"><div><p class="eyebrow">Finalized accounting records</p><h2>Statements</h2><p>Opening balances, Publisher earnings, deductions, payments, invoices, and carry-forward remain separated by currency and period.</p></div></section>
<div class="table-wrap">
    <table>
        <thead><tr><th>Statement</th><th>Period</th><th>Status</th><th>Publisher earnings</th><th>Paid</th><th>Balance due</th><th>Carry-forward</th><th>Invoice</th><th></th></tr></thead>
        <tbody>
        @forelse($statements as $statement)
            <tr>
                <td><strong>{{ $statement->statement_number }}</strong><span class="table-note">Finalized {{ $statement->finalized_at?->toDateString() ?: '—' }}</span></td>
                <td>{{ $statement->period->period_key }}<span class="table-note">{{ $statement->currency }}</span></td>
                <td><span class="pill">{{ $statement->status->value }}</span></td>
                <td>{{ $statement->currency }} {{ \App\Support\Money::formatMinor((int) $statement->publisher_earnings_minor) }}</td>
                <td>{{ $statement->currency }} {{ \App\Support\Money::formatMinor((int) $statement->paid_minor) }}</td>
                <td>{{ $statement->currency }} {{ \App\Support\Money::formatMinor((int) $statement->balance_due_minor) }}</td>
                <td>{{ $statement->currency }} {{ \App\Support\Money::formatMinor((int) $statement->carry_forward_minor) }}</td>
                <td><span class="pill">{{ $statement->publisher_invoice_status->value }}</span></td>
                <td><a href="{{ route('publisher.finance.statements.show', $statement) }}">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="9">Statements appear after a financial period is finalized.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
