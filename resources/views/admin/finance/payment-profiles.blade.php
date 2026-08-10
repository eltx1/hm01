@extends('layouts.admin')
@section('title', 'Payment Profile Verification')
@section('heading', 'Payment Profile Verification')
@section('content')
@include('admin.finance._tabs')
<section class="hero"><div><p class="eyebrow">Masked verification queue</p><h2>Review payout destinations safely</h2><p>Only operationally necessary masked values appear here. Raw account, routing, and tax values are not rendered or placed in audit metadata.</p></div></section>
<form method="get" class="form-grid workspace-section"><label>Status<select name="status"><option value="">All</option>@foreach($profileStatuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ str($status->value)->headline() }}</option>@endforeach</select></label><button class="hm-button-secondary">Filter queue</button></form>
<article class="workspace-section"><div class="table-wrap"><table><thead><tr><th>Publisher</th><th>Status</th><th>Beneficiary</th><th>Destination</th><th>Currency / Country</th><th>Review</th></tr></thead><tbody>
@forelse($publishers as $publisher)
@php($profile = $publisher->paymentProfile)
<tr>
    <td><strong>{{ $publisher->display_name }}</strong><span class="table-note">{{ $publisher->billing_email ?: 'No billing email' }}</span></td>
    <td><x-status-badge :status="$profile?->verification_status ?? 'INCOMPLETE'" />@if($profile?->verification_requested_at)<span class="table-note">Requested {{ $profile->verification_requested_at->toDateString() }}</span>@endif</td>
    <td>{{ $profile?->beneficiary_name ?: 'Missing' }}</td>
    <td>{{ $profile?->payment_method ?: 'Missing' }}<span class="table-note">{{ $profile?->maskedAccountReference() ?: 'No masked account suffix' }}</span></td>
    <td>{{ $profile?->currency ?: '—' }} · {{ $profile?->country ?: '—' }}</td>
    <td>
        @if($profile)
            @if(auth()->user()->hasPermission('publisher_payments.manage'))<a class="text-link" href="{{ route('admin.publishers.payment-profile.edit', $publisher) }}">Open masked profile</a>@endif
            @if(auth()->user()->hasPermission('finance.payment_profiles.verify') && $profile->verification_status !== \App\Enums\PublisherPaymentProfileStatus::Incomplete)
                <details><summary class="text-link">Record decision</summary><form method="post" action="{{ route('admin.finance.payment-profiles.review', $profile) }}" class="form-stack">@csrf<select name="verification_status" required><option value="VERIFIED">Verified</option><option value="REJECTED">Rejected</option><option value="PENDING_VERIFICATION">Return to pending</option></select><label>Safe reason<input class="hm-input" name="verification_reason" maxlength="1000"></label><button class="hm-button-primary">Save decision</button></form></details>
            @endif
        @else<span class="muted">Publisher action required.</span>@endif
    </td>
</tr>
@empty<tr><td colspan="6" class="muted">No profiles match the selected queue.</td></tr>@endforelse
</tbody></table></div></article>
{{ $publishers->links() }}
@endsection
