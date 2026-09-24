@extends('layouts.admin')
@section('title', 'Statements & invoices')
@section('heading', 'Statements & invoices')
@section('content')
@include('publisher.finance._tabs')
<section class="hero"><div><p class="eyebrow">Your monthly accounting</p><h2>Statements &amp; invoices</h2><p>Open a month to see its earnings, adjustments and carry-forward, upload your invoice, or follow its payment. Each statement keeps its original currency.</p></div></section>
<div class="table-wrap">
    <table>
        <caption class="sr-only">Monthly statements and invoice progress</caption>
        <thead><tr><th scope="col">Month / statement</th><th scope="col">Earnings</th><th scope="col">Paid</th><th scope="col">Balance due</th><th scope="col">Invoice</th><th scope="col">Details</th></tr></thead>
        <tbody>
        @forelse($statements as $statement)
            <tr>
                <th scope="row"><strong>{{ $statement['period_key'] }} · {{ $statement['currency'] }}</strong><span class="table-note">{{ $statement['statement_number'] }}</span><x-status-badge :status="$statement['status']" /></th>
                <td class="money">{{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) $statement['publisher_earnings_minor']) }}<span class="table-note">Referral: {{ \App\Support\Money::formatMinor((int) $statement['affiliate_earnings_minor']) }}</span></td>
                <td class="money">{{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) $statement['paid_minor']) }}</td>
                <td class="money">{{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) $statement['balance_due_minor']) }}<span class="table-note">Carry-forward: {{ \App\Support\Money::formatMinor((int) $statement['carry_forward_minor']) }}</span></td>
                <td><x-status-badge :status="$statement['publisher_invoice_status']" /></td>
                <td><a class="section-anchor" href="{{ route('publisher.finance.statements.show', $statement['id']) }}">View statement →</a></td>
            </tr>
        @empty
            <tr><td colspan="6">Statements appear after a financial period is finalized.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
