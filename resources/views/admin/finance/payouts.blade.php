@extends('layouts.admin')
@section('title', 'Payout Workbench')
@section('heading', 'Payout Workbench')
@section('content')
@include('admin.finance._tabs')
<section class="hero"><div><p class="eyebrow">Maker-checker settlement</p><h2>Approve, process externally, then settle</h2><p>Approval never claims funds moved. Only an authorized settlement with a real immutable reference reduces Publisher liability.</p></div><a class="hm-button-secondary button-link" href="{{ route('admin.finance.payouts.csv', request()->query()) }}">Export safe CSV</a></section>
<form method="get" class="form-grid workspace-section"><label>Status<select name="status"><option value="">All</option>@foreach($paymentStatuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ str($status->value)->headline() }}</option>@endforeach</select></label><label>Currency<input name="currency" maxlength="3" value="{{ request('currency') }}"></label><button class="hm-button-secondary">Filter</button></form>
<article class="workspace-section"><div class="table-wrap"><table><thead><tr><th>Payout</th><th>Publisher / Statement</th><th>Amounts</th><th>Method / Dates</th><th>Settlement references</th><th>Operations</th></tr></thead><tbody>
@forelse($payments as $payment)
<tr>
    <td><strong>{{ $payment->payment_number }}</strong><span class="table-note"><x-status-badge :status="$payment->status" /></span><span class="table-note">Created by {{ $payment->creator?->name ?: 'System' }}</span>@if($payment->approver)<span class="table-note">Approved by {{ $payment->approver->name }}</span>@endif</td>
    <td>{{ $payment->publisher->display_name }}<span class="table-note"><a class="text-link" href="{{ route('admin.finance.statements.show', $payment->statement) }}">{{ $payment->statement->statement_number }}</a> · {{ $payment->statement->period->period_key }}</span></td>
    <td><strong>{{ \App\Support\Money::formatMinor((int) $payment->amount_minor) }} {{ $payment->currency }}</strong><span class="table-note">Settled {{ \App\Support\Money::formatMinor((int) $payment->settled_amount_minor) }}</span><span class="table-note">Remaining {{ \App\Support\Money::formatMinor($payment->remainingAmountMinor()) }}</span></td>
    <td>{{ $payment->payment_method ?: 'Not specified' }}<span class="table-note">Scheduled {{ $payment->scheduled_on?->toDateString() ?: '—' }}</span><span class="table-note">Last settlement {{ $payment->paid_at?->toDateString() ?: '—' }}</span>@if($payment->publisher_message)<span class="table-note error">{{ $payment->publisher_message }}</span>@endif</td>
    <td>@forelse($payment->settlements as $settlement)<span class="table-note"><strong>{{ $settlement->settlement_reference }}</strong> · {{ \App\Support\Money::formatMinor((int) $settlement->amount_minor) }} · {{ $settlement->settled_on->toDateString() }}</span>@empty<span class="muted">No settlement recorded.</span>@endforelse</td>
    <td>
        @if($payment->status === \App\Enums\PublisherPaymentStatus::Pending && auth()->user()->hasPermission('finance.payments.approve') && $payment->created_by !== auth()->id())
            <form method="post" action="{{ route('admin.finance.payouts.approve', $payment) }}" class="inline-form">@csrf<input type="hidden" name="confirm_approval" value="1"><button class="hm-button-primary">Approve</button></form>
        @endif
        @if($payment->status === \App\Enums\PublisherPaymentStatus::Approved && auth()->user()->hasPermission('finance.payments.settle'))
            <form method="post" action="{{ route('admin.finance.payouts.schedule', $payment) }}" class="inline-form">@csrf<input class="hm-input" type="date" name="scheduled_on" min="{{ now()->toDateString() }}" required><button class="hm-button-secondary">Schedule</button></form>
        @endif
        @if(in_array($payment->status, [\App\Enums\PublisherPaymentStatus::Approved, \App\Enums\PublisherPaymentStatus::Scheduled], true) && auth()->user()->hasPermission('finance.payments.settle'))
            <form method="post" action="{{ route('admin.finance.payouts.process', $payment) }}" class="inline-form">@csrf<input type="hidden" name="confirm_external_processing" value="1"><button class="hm-button-secondary">Record external processing</button></form>
        @endif
        @if(in_array($payment->status, [\App\Enums\PublisherPaymentStatus::Approved, \App\Enums\PublisherPaymentStatus::Scheduled, \App\Enums\PublisherPaymentStatus::Processing, \App\Enums\PublisherPaymentStatus::PartiallyPaid], true) && auth()->user()->hasPermission('finance.payments.settle'))
            <details><summary class="text-link">Record settlement</summary><form method="post" action="{{ route('admin.finance.payouts.settle', $payment) }}" class="form-stack">@csrf<label>Immutable reference<input class="hm-input" name="settlement_reference" required maxlength="255"></label><label>Amount in minor units<input class="hm-input" type="number" name="amount_minor" min="1" max="{{ $payment->remainingAmountMinor() }}" value="{{ $payment->remainingAmountMinor() }}" required></label><label>Settlement date<input class="hm-input" type="date" name="settled_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label><button class="hm-button-primary">Record actual settlement</button></form></details>
        @endif
        @if(!$payment->status->isTerminal() && $payment->status !== \App\Enums\PublisherPaymentStatus::Held && auth()->user()->hasPermission('finance.payments.settle'))
            <details><summary class="text-link">Hold or fail</summary><form method="post" action="{{ route('admin.finance.payouts.hold', $payment) }}" class="inline-form">@csrf<input class="hm-input" name="reason" required maxlength="1000" placeholder="Safe Publisher-visible reason"><button class="hm-button-secondary">Hold</button></form><form method="post" action="{{ route('admin.finance.payouts.fail', $payment) }}" class="inline-form">@csrf<input class="hm-input" name="reason" required maxlength="1000" placeholder="Safe Publisher-visible reason"><button class="hm-button-danger">Fail</button></form></details>
        @elseif($payment->status === \App\Enums\PublisherPaymentStatus::Held && auth()->user()->hasPermission('finance.payments.settle'))
            <form method="post" action="{{ route('admin.finance.payouts.release', $payment) }}" class="inline-form">@csrf<input type="hidden" name="confirm_release" value="1"><button class="hm-button-secondary">Release hold</button></form>
        @endif
    </td>
</tr>
@empty<tr><td colspan="6" class="muted">No payouts match the filters.</td></tr>@endforelse
</tbody></table></div></article>
{{ $payments->links() }}
@endsection
