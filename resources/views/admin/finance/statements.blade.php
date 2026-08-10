@extends('layouts.admin')
@section('title', 'Publisher Statements')
@section('heading', 'Publisher Statements')
@section('content')
@include('admin.finance._tabs')
<section class="hero"><div><p class="eyebrow">Eligible statements</p><h2>Validate, select, and create payouts</h2><p>Eligibility requires a payable statement, accepted invoice when required, verified currency-matched payment destination, and unreserved balance.</p></div></section>
<form method="get" class="form-grid workspace-section">
    <label>Status<select name="status"><option value="">All</option>@foreach($statementStatuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ str($status->value)->headline() }}</option>@endforeach</select></label>
    <label>Invoice<select name="invoice_status"><option value="">All</option>@foreach($invoiceStatuses as $status)<option value="{{ $status->value }}" @selected(request('invoice_status') === $status->value)>{{ str($status->value)->headline() }}</option>@endforeach</select></label>
    <label>Currency<input name="currency" maxlength="3" value="{{ request('currency') }}"></label><button class="hm-button-secondary">Filter</button>
</form>
<form method="post" action="{{ route('admin.finance.payouts.create-selected') }}" class="workspace-section">@csrf
    <input type="hidden" name="batch_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    <article><div class="workspace-heading"><div><p class="eyebrow">Payout selection</p><h2>Statement workbench</h2></div>@if(auth()->user()->hasPermission('finance.payments.create'))<button class="hm-button-primary">Create payouts for selected eligible statements</button>@endif</div>
    <div class="table-wrap"><table><thead><tr><th>Select</th><th>Statement / Publisher</th><th>Period</th><th>Balance</th><th>Invoice</th><th>Payment profile</th><th>Eligibility</th><th>Actions</th></tr></thead><tbody>
    @forelse($statements as $statement)
        @php($eligible = $finance->isEligible($statement))
        @php($available = $finance->unreservedBalance($statement))
        <tr>
            <td><input type="checkbox" name="statement_ids[]" value="{{ $statement->id }}" @disabled(!$eligible) aria-label="Select {{ $statement->statement_number }}"></td>
            <td><strong><a class="text-link" href="{{ route('admin.finance.statements.show', $statement) }}">{{ $statement->statement_number }}</a></strong><span class="table-note">{{ $statement->publisher->display_name }}</span><x-status-badge :status="$statement->status" /></td>
            <td>{{ $statement->period->period_key }}<span class="table-note">Finalized {{ $statement->finalized_at?->toDateString() ?: '—' }}</span></td>
            <td><strong>{{ \App\Support\Money::formatMinor((int) $statement->balance_due_minor) }} {{ $statement->currency }}</strong><span class="table-note">Unreserved {{ \App\Support\Money::formatMinor($available) }}</span></td>
            <td><x-status-badge :status="$statement->publisher_invoice_status" /><span class="table-note">{{ $statement->publisher_invoice_number ?: 'No invoice number' }}</span>
                @if($statement->publisher_invoice_status === \App\Enums\PublisherInvoiceStatus::Received && auth()->user()->hasPermission('finance.statements.review'))
                    <details><summary class="text-link">Review invoice</summary>
                        <form method="post" action="{{ route('admin.finance.statements.invoice-review', $statement) }}" class="form-stack">@csrf<select name="publisher_invoice_status" required><option value="ACCEPTED">Accept</option><option value="REJECTED">Reject</option></select><label>Reason if rejected<input class="hm-input" name="review_reason" maxlength="1000"></label><button class="hm-button-secondary">Record decision</button></form>
                    </details>
                @endif
            </td>
            <td>@if($statement->publisher->paymentProfile)<x-status-badge :status="$statement->publisher->paymentProfile->verification_status" /><span class="table-note">{{ $statement->publisher->paymentProfile->payment_method }} · {{ $statement->publisher->paymentProfile->maskedAccountReference() ?: 'No account suffix' }}</span>@else<x-status-badge status="INCOMPLETE" />@endif</td>
            <td><x-status-badge :status="$eligible ? 'ELIGIBLE' : 'BLOCKED'" /></td>
            <td>
                <a class="text-link" href="{{ route('admin.finance.statements.csv', $statement) }}">CSV</a>
                @if($eligible && auth()->user()->hasPermission('finance.payments.create'))
                    <details><summary class="text-link">Create partial payout</summary><form method="post" action="{{ route('admin.finance.payouts.store', $statement) }}" class="form-stack">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><label>Amount in minor units<input class="hm-input" type="number" min="1" max="{{ $available }}" name="amount_minor" value="{{ $available }}" required></label><label>Internal note<input class="hm-input" name="notes" maxlength="10000"></label><button class="hm-button-primary">Create for approval</button></form></details>
                @endif
            </td>
        </tr>
    @empty<tr><td colspan="8" class="muted">No statements match the filters.</td></tr>@endforelse
    </tbody></table></div></article>
</form>
{{ $statements->links() }}
@endsection
