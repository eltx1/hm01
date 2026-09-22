@extends('layouts.admin')
@section('title', $statement['statement_number'])
@section('heading', 'Publisher statement')
@section('content')
@include('publisher.finance._tabs')
<section class="hero">
    <div><x-brand.full-logo class="statement-brand-logo" /><p class="eyebrow">{{ $statement['statement_number'] }}</p><h2>{{ $statement['period_key'] }} · {{ $statement['currency'] }}</h2><p>Finalized {{ $statement['finalized_at']?->toDateString() ?: '—' }} · Status {{ $statement['status'] }}</p></div>
    <a class="hm-button-primary button-link" href="{{ route('publisher.finance.statements.csv', $statement['id']) }}">Download safe CSV</a>
</section>
<section class="metric-grid">
    @foreach([
        ['Opening balance', $statement['opening_balance_minor']],
        ['Publisher earnings', $statement['publisher_earnings_minor']],
        ['Affiliate earnings', $statement['affiliate_earnings_minor']],
        ['Paid', $statement['paid_minor']],
        ['Balance due', $statement['balance_due_minor']],
        ['Carry-forward', $statement['carry_forward_minor']],
        ['Payment threshold', $statement['payment_threshold_minor']],
    ] as [$label, $minor])
        <article><p class="eyebrow">{{ $label }}</p><strong class="metric-small">{{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) $minor) }}</strong></article>
    @endforeach
</section>

<article>
    <p class="eyebrow">Publisher-visible detail</p><h2>Earnings lines</h2>
    @forelse($statement['line_items'] as $line)
        @php($isAffiliate = ($line['kind'] ?? '') === 'AFFILIATE')
        <div class="event">
            <div>
                <strong>
                    @if($isAffiliate)
                        Referral commission{{ filled($line['referred_publisher'] ?? null) ? ' · '.$line['referred_publisher'] : '' }}
                    @elseif(($line['kind'] ?? '') === 'ADJUSTMENT')
                        Approved adjustment
                    @else
                        {{ $line['site'] ?? 'All Publisher inventory' }}
                    @endif
                </strong><br>
                @if($isAffiliate)
                    <span>{{ number_format(((int) ($line['affiliate_commission_rate_bp'] ?? 0)) / 100, 2) }}% of finalized referred Publisher earnings</span>
                @else
                    <span>{{ number_format((int) ($line['impressions'] ?? 0)) }} impressions</span>
                @endif
            </div>
            <span>{{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) ($line['amount_minor'] ?? 0)) }}</span>
        </div>
    @empty<p class="muted">No statement lines.</p>@endforelse
</article>

<article>
    <p class="eyebrow">Private document</p><h2>Publisher invoice</h2>
    <div class="summary-grid">
        <div><strong>Required</strong><span>{{ $statement['publisher_invoice_status'] !== 'NOT_REQUIRED' ? 'Yes' : 'No' }}</span></div>
        <div><strong>Validation status</strong><span>{{ $statement['publisher_invoice_status'] }}</span></div>
        <div><strong>Invoice number</strong><span>{{ $statement['publisher_invoice_number'] ?: '—' }}</span></div>
        <div><strong>Uploaded</strong><span>{{ $statement['publisher_invoice_uploaded_at']?->toDateString() ?: '—' }}</span></div>
    </div>
    @if($statement['publisher_invoice_review_reason'])<p class="muted">Finance response: {{ $statement['publisher_invoice_review_reason'] }}</p>@endif
    @if($statement['has_publisher_invoice'])
        <a class="hm-button-secondary button-link" href="{{ route('publisher.finance.statements.invoice.download', $statement['id']) }}">Download my private invoice</a>
    @endif
    @if(in_array($statement['publisher_invoice_status'], ['REQUIRED', 'REJECTED'], true) && auth()->user()->hasPermission('finance.publisher.invoice.upload'))
        <form method="post" enctype="multipart/form-data" action="{{ route('publisher.finance.statements.invoice', $statement['id']) }}" class="form-grid">
            @csrf
            <label>Invoice number<input class="hm-input" name="invoice_number" required maxlength="128"></label>
            <label>Private PDF or image<input class="hm-input" type="file" name="invoice" required accept=".pdf,.png,.jpg,.jpeg"></label>
            <button class="hm-button-primary" type="submit">Upload private invoice</button>
        </form>
    @elseif($statement['publisher_invoice_status'] === 'RECEIVED')
        <p class="muted">The invoice was received and is awaiting Finance processing. No payment is represented as paid until settlement is recorded.</p>
    @elseif($statement['publisher_invoice_status'] === 'NOT_REQUIRED')
        <p class="muted">No invoice is required for this below-threshold statement.</p>
    @endif
</article>

<article>
    <p class="eyebrow">Payout relationship</p><h2>Payments for this statement</h2>
    @forelse($statement['payments'] as $payment)
        <div class="event"><div><strong>{{ $payment['payment_number'] }}</strong><br><span>{{ $payment['payment_method'] ?: 'Method pending' }} · {{ $payment['scheduled_on']?->toDateString() ?: 'Not scheduled' }} · {{ $payment['horus_payment_reference'] ?: 'No settlement reference' }}</span></div><span>{{ $payment['currency'] }} {{ \App\Support\Money::formatMinor((int) $payment['settled_amount_minor']) }} settled of {{ \App\Support\Money::formatMinor((int) $payment['amount_minor']) }} · {{ $payment['status'] }}</span></div>
    @empty<p class="muted">No payout has been created for this statement.</p>@endforelse
</article>
@endsection
