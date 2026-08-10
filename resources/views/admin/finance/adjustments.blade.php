@extends('layouts.admin')
@section('title', 'Revenue Adjustments')
@section('heading', 'Revenue Adjustments')
@section('content')
@include('admin.finance._tabs')
<section class="hero"><div><p class="eyebrow">Maker-checker adjustments</p><h2>Explicit Publisher and Horus impact</h2><p>Adjustments use integer minor units, the selected period currency, a documented reason, and a different actor for approval or rejection.</p></div></section>
@if(auth()->user()->hasPermission('finance.adjustments.create'))
<article class="workspace-section"><div class="workspace-heading"><div><p class="eyebrow">New adjustment</p><h2>Create pending adjustment</h2></div></div><form method="post" action="{{ route('admin.finance.adjustments.store') }}" class="form-grid">@csrf
    <label>Financial period<select name="financial_period_id" required>@foreach($periods as $period)<option value="{{ $period->id }}">{{ $period->period_key }} · {{ $period->currency }}</option>@endforeach</select></label>
    <label>Publisher<select name="publisher_id"><option value="">No Publisher scope</option>@foreach($publishers as $publisher)<option value="{{ $publisher->id }}">{{ $publisher->display_name }}</option>@endforeach</select></label>
    <label>Website<select name="site_id"><option value="">No website scope</option>@foreach($sites as $site)<option value="{{ $site->id }}">{{ $site->publisher?->display_name }} · {{ $site->display_name }}</option>@endforeach</select></label>
    <label>Effective date<input class="hm-input" type="date" name="effective_on" required></label>
    <label>Type<select name="type" required><option value="DEMAND_PARTNER_DEDUCTION">Demand partner deduction</option><option value="INVALID_TRAFFIC">Invalid traffic</option><option value="OTHER_APPROVED">Other approved</option></select></label>
    <label>Amount in minor units<input class="hm-input" type="number" name="amount_minor" min="1" required></label>
    <label>Currency<input class="hm-input" name="currency" maxlength="3" required></label>
    <label class="full">Reason<textarea class="hm-input" name="reason" required maxlength="10000"></textarea></label><button class="hm-button-primary">Create for independent decision</button>
</form></article>
@endif
<article class="workspace-section"><div class="table-wrap"><table><thead><tr><th>Period / Scope</th><th>Type / Reason</th><th>Amount</th><th>Impact</th><th>Status / Actors</th><th>Decision</th></tr></thead><tbody>
@forelse($adjustments as $adjustment)
<tr><td>{{ $adjustment->period?->period_key }} · {{ $adjustment->currency }}<span class="table-note">{{ $adjustment->publisher?->display_name ?: 'Global/internal scope' }}</span>@if($adjustment->site)<span class="table-note">Website: {{ $adjustment->site->display_name }}</span>@endif @if($adjustment->campaign)<span class="table-note">Campaign: {{ $adjustment->campaign->name }}</span>@endif</td><td>{{ str($adjustment->type)->headline() }}<span class="table-note">{{ $adjustment->reason }}</span><span class="table-note">Created {{ $adjustment->created_at->toDateTimeString() }}</span></td><td>{{ \App\Support\Money::formatMinor((int) $adjustment->amount_minor) }} {{ $adjustment->currency }}</td><td><span class="table-note">Publisher {{ \App\Support\Money::formatMinor((int) data_get($adjustment->metadata, 'publisher_impact_minor', 0)) }}</span><span class="table-note">Horus {{ \App\Support\Money::formatMinor((int) data_get($adjustment->metadata, 'horus_impact_minor', 0)) }}</span></td><td><x-status-badge :status="$adjustment->status" /><span class="table-note">Creator: {{ $adjustment->creator?->name ?: 'System' }}</span>
    @if($adjustment->approver)<span class="table-note">Approver: {{ $adjustment->approver->name }}</span>@endif
    @if($adjustment->approved_at)<span class="table-note">Approved {{ $adjustment->approved_at->toDateTimeString() }}</span>@endif
    @if($adjustment->rejected_at)<span class="table-note">Rejected {{ $adjustment->rejected_at->toDateTimeString() }}</span>@endif
    @if($adjustment->decision_reason)<span class="table-note">{{ $adjustment->decision_reason }}</span>@endif
</td><td>
    @if($adjustment->status === \App\Enums\AdjustmentStatus::Pending && auth()->user()->hasPermission('finance.adjustments.approve') && $adjustment->created_by !== auth()->id())
        <form method="post" action="{{ route('admin.finance.adjustments.approve', $adjustment) }}" class="inline-form">@csrf<input class="hm-input" name="decision_reason" maxlength="2000" placeholder="Optional approval note"><button class="hm-button-primary">Approve</button></form>
        <form method="post" action="{{ route('admin.finance.adjustments.reject', $adjustment) }}" class="inline-form">@csrf<input class="hm-input" name="decision_reason" required maxlength="2000" placeholder="Required rejection reason"><button class="hm-button-danger">Reject</button></form>
    @else<span class="muted">No decision available.</span>@endif
</td></tr>
@empty<tr><td colspan="6" class="muted">No revenue adjustments exist.</td></tr>@endforelse
</tbody></table></div></article>
{{ $adjustments->links() }}
@endsection
