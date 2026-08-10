@extends('layouts.admin')
@section('title', 'Publisher payment profile')
@section('heading', $publisher->display_name.' payment profile')
@section('content')
<section class="hero"><div><p class="eyebrow">Encrypted financial profile</p><h2>{{ $profile?->verification_status?->value ?? 'INCOMPLETE' }}</h2><p>Account, routing, and tax values remain encrypted. Saved account reference: {{ $profile?->maskedAccountReference() ?: 'None' }}. Raw values are never rendered or audited.</p></div></section>
<article>
    <h2>Operational profile</h2>
    <form method="POST" action="{{ route('admin.publishers.payment-profile.update', $publisher) }}" class="form-grid" autocomplete="off">
        @csrf @method('PUT')
        <label>Beneficiary name<input class="hm-input" name="beneficiary_name" value="{{ old('beneficiary_name', $profile?->beneficiary_name) }}" required></label>
        <label>Payment method<select class="hm-input" name="payment_method">@foreach(['BANK_TRANSFER','PAYPAL','WISE','OTHER'] as $method)<option value="{{ $method }}" @selected(old('payment_method', $profile?->payment_method) === $method)>{{ $method }}</option>@endforeach</select></label>
        <label>Currency<input class="hm-input" name="currency" value="{{ old('currency', $profile?->currency ?: 'USD') }}" maxlength="3" required></label>
        <label>Country<input class="hm-input" name="country" value="{{ old('country', $profile?->country) }}" maxlength="2" required></label>
        <label class="full">Billing address<input class="hm-input" name="billing_address" value="{{ old('billing_address', $profile?->billing_address) }}"></label>
        <label>Replacement account reference<input class="hm-input" name="account_reference" value="" autocomplete="new-password"></label>
        <label>Replacement routing/SWIFT<input class="hm-input" name="routing_reference" value="" autocomplete="new-password"></label>
        <label>Replacement tax identifier<input class="hm-input" name="tax_identifier" value="" autocomplete="new-password"></label>
        <button class="hm-button-primary">Save profile</button>
    </form>
</article>
@if($profile && auth()->user()->hasPermission('finance.payment_profiles.verify'))
<article>
    <p class="eyebrow">Separate Finance action</p><h2>Verification decision</h2>
    <form method="POST" action="{{ route('admin.publishers.payment-profile.review', $publisher) }}" class="form-grid">
        @csrf
        <label>Status<select class="hm-input" name="verification_status"><option value="VERIFIED">Verified</option><option value="PENDING_VERIFICATION">Pending verification</option><option value="REJECTED">Rejected</option></select></label>
        <label class="full">Safe Publisher-visible reason<textarea class="hm-input" name="verification_reason" maxlength="1000"></textarea></label>
        <button class="hm-button-primary">Record verification decision</button>
    </form>
</article>
@endif
@endsection
