@extends('layouts.admin')
@section('title', $statement->statement_number)
@section('heading', 'Publisher statement')
@section('content')
@include('publisher.finance._tabs')
<section class="hero">
    <div><x-brand.full-logo class="statement-brand-logo" /><p class="eyebrow">{{ $statement->statement_number }}</p><h2>{{ $statement->period->period_key }} · {{ $statement->currency }}</h2><p>Finalized {{ $statement->finalized_at?->toDateString() ?: '—' }} · Status {{ $statement->status->value }}</p></div>
    <a class="hm-button-primary button-link" href="{{ route('publisher.finance.statements.csv', $statement) }}">Download safe CSV</a>
</section>
<section class="metric-grid">
    @foreach([
        ['Opening balance', $statement->opening_balance_minor],
        ['Publisher earnings', $statement->publisher_earnings_minor],
        ['Deductions', $statement->deductions_minor],
        ['Paid', $statement->paid_minor],
        ['Balance due', $statement->balance_due_minor],
        ['Carry-forward', $statement->carry_forward_minor],
        ['Payment threshold', $statement->payment_threshold_minor],
    ] as [$label, $minor])
        <article><p class="eyebrow">{{ $label }}</p><strong class="metric-small">{{ $statement->currency }} {{ \App\Support\Money::formatMinor((int) $minor) }}</strong></article>
    @endforeach
</section>

<article>
    <p class="eyebrow">Publisher-visible detail</p><h2>Earnings lines</h2>
    @forelse($statement->line_items as $line)
        <div class="event">
            <div><strong>{{ ($line['source'] ?? '') === 'ADJUSTMENT' ? 'Approved adjustment' : ($line['site'] ?? 'All Publisher inventory') }}</strong><br><span>{{ number_format((int) ($line['impressions'] ?? 0)) }} impressions</span></div>
            <span>{{ $statement->currency }} {{ \App\Support\Money::formatMinor((int) ($line['publisher_earnings_minor'] ?? 0)) }}</span>
        </div>
    @empty<p class="muted">No statement lines.</p>@endforelse
</article>

<article>
    <p class="eyebrow">Private document</p><h2>Publisher invoice</h2>
    <div class="summary-grid">
        <div><strong>Required</strong><span>{{ $statement->invoiceRequired() ? 'Yes' : 'No' }}</span></div>
        <div><strong>Validation status</strong><span>{{ $statement->publisher_invoice_status->value }}</span></div>
        <div><strong>Invoice number</strong><span>{{ $statement->publisher_invoice_number ?: '—' }}</span></div>
        <div><strong>Uploaded</strong><span>{{ $statement->publisher_invoice_uploaded_at?->toDateString() ?: '—' }}</span></div>
    </div>
    @if($statement->publisher_invoice_review_reason)<p class="muted">Finance response: {{ $statement->publisher_invoice_review_reason }}</p>@endif
    @if($statement->publisher_invoice_path)
        <a class="hm-button-secondary button-link" href="{{ route('publisher.finance.statements.invoice.download', $statement) }}">Download my private invoice</a>
    @endif
    @if(in_array($statement->publisher_invoice_status, [\App\Enums\PublisherInvoiceStatus::Required, \App\Enums\PublisherInvoiceStatus::Rejected], true) && auth()->user()->hasPermission('finance.publisher.invoice.upload'))
        <form method="post" enctype="multipart/form-data" action="{{ route('publisher.finance.statements.invoice', $statement) }}" class="form-grid">
            @csrf
            <label>Invoice number<input class="hm-input" name="invoice_number" required maxlength="128"></label>
            <label>Private PDF or image<input class="hm-input" type="file" name="invoice" required accept=".pdf,.png,.jpg,.jpeg"></label>
            <button class="hm-button-primary" type="submit">Upload private invoice</button>
        </form>
    @elseif($statement->publisher_invoice_status === \App\Enums\PublisherInvoiceStatus::Received)
        <p class="muted">The invoice was received and is awaiting Finance processing. No payment is represented as paid until settlement is recorded.</p>
    @elseif($statement->publisher_invoice_status === \App\Enums\PublisherInvoiceStatus::NotRequired)
        <p class="muted">No invoice is required for this below-threshold statement.</p>
    @endif
</article>

<article>
    <p class="eyebrow">Payout relationship</p><h2>Payments for this statement</h2>
    @forelse($statement->payments as $payment)
        <div class="event"><div><strong>{{ $payment->payment_number }}</strong><br><span>{{ $payment->payment_method ?: 'Method pending' }} · {{ $payment->scheduled_on?->toDateString() ?: 'Not scheduled' }} · {{ $payment->horus_payment_reference ?: 'No settlement reference' }}</span></div><span>{{ $payment->currency }} {{ \App\Support\Money::formatMinor((int) $payment->settled_amount_minor) }} settled of {{ \App\Support\Money::formatMinor((int) $payment->amount_minor) }} · {{ $payment->status->value }}</span></div>
    @empty<p class="muted">No payout has been created for this statement.</p>@endforelse
</article>
@endsection
