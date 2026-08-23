@extends('layouts.admin')
@section('title', $contract->exists ? 'Edit commercial terms' : 'New commercial terms')
@section('heading', $contract->exists ? 'Edit commercial terms' : 'New commercial terms')
@section('content')
<section class="hero"><div><p class="eyebrow">{{ $publisher->display_name }}</p><h2>Commercial terms, not a document workflow.</h2><p>The publisher already accepted the platform terms during registration. This record controls the default commercial share and payment display; demand-specific rules can override it.</p></div></section>
<article>
    <form method="POST" action="{{ $contract->exists ? route('admin.publishers.contracts.update', [$publisher, $contract]) : route('admin.publishers.contracts.store', $publisher) }}" class="form-grid">@csrf @if($contract->exists)@method('PUT')@endif
        <label>Terms reference<input class="hm-input" name="contract_reference" value="{{ old('contract_reference', $contract->contract_reference) }}" required></label>
        <label>Effective from<input class="hm-input" type="date" name="starts_at" value="{{ old('starts_at', $contract->starts_at?->toDateString()) }}"></label>
        <label>Effective to (optional)<input class="hm-input" type="date" name="ends_at" value="{{ old('ends_at', $contract->ends_at?->toDateString()) }}"></label>
        <label>Default publisher share %<input class="hm-input" type="number" min="0" max="100" step=".01" name="revenue_share_percent" value="{{ old('revenue_share_percent', $contract->revenue_share_percent ?? 70) }}" required><span class="muted">Used when no more-specific demand, website, or campaign rule exists.</span></label>
        <label>Payment threshold<input class="hm-input" type="number" min="0" step=".01" name="payment_threshold" value="{{ old('payment_threshold', $contract->payment_threshold ?? 100) }}" required></label>
        <label>Currency<input class="hm-input" name="currency" value="{{ old('currency', $contract->currency ?: 'USD') }}" maxlength="3" required></label>
        <label>Payment terms<input class="hm-input" name="payment_terms" value="{{ old('payment_terms', $contract->payment_terms ?: 'NET_30') }}" required></label>
        <label class="full">Internal note (optional)<textarea class="hm-input" name="internal_notes">{{ old('internal_notes', $contract->internal_notes) }}</textarea></label>
        <button class="hm-button-primary">Save commercial terms</button>
    </form>
</article>

@if($contract->exists)
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Status</p><h2>{{ str($contract->status->value)->headline() }}</h2><p>@if($contract->status === \App\Enums\ContractStatus::Active)The default Publisher revenue rule is synchronized automatically.@elseif($allowedStatuses === [])No further action is available for this record.@elseChoose the required action. No upload or signature step is required.@endif</p></div><x-status-badge :status="$contract->status" /></div>
    @if($allowedStatuses !== [])
    <form method="POST" action="{{ route('admin.publishers.contracts.status', [$publisher, $contract]) }}" class="form-grid">@csrf
        <label class="full">Reason (optional)<input class="hm-input" name="reason" maxlength="2000" placeholder="Commercial decision note"></label>
        <div class="status-row full">
            @foreach($allowedStatuses as $status)
                <button class="{{ $status === \App\Enums\ContractStatus::Active ? 'hm-button-primary' : 'hm-button-secondary' }}" name="status" value="{{ $status->value }}">{{ match($status) {
                    \App\Enums\ContractStatus::Active => 'Activate terms',
                    \App\Enums\ContractStatus::Draft => 'Return to draft',
                    \App\Enums\ContractStatus::Expired => 'Mark expired',
                    \App\Enums\ContractStatus::Terminated => 'Terminate terms',
                    default => str($status->value)->headline(),
                } }}</button>
            @endforeach
        </div>
    </form>
    @endif
</article>
@endif
@endsection
