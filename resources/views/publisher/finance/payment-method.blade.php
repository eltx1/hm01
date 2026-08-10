@extends('layouts.admin')
@section('title', 'Payment Method')
@section('heading', 'Payment Method')
@section('content')
@include('publisher.finance._tabs')
<section class="hero"><div><p class="eyebrow">Encrypted payout destination</p><h2>{{ $profile?->verification_status?->value ?? 'INCOMPLETE' }}</h2><p>Sensitive account, routing, and tax values are encrypted and never redisplayed. Saved account: {{ $profile?->maskedAccountReference() ?: 'None' }}.</p></div></section>
@if($profile?->verification_reason)<article><p class="eyebrow">Finance review</p><h2>Review response</h2><p>{{ $profile->verification_reason }}</p></article>@endif
<article>
    <h2>Manage payment destination</h2>
    @if($profile?->verification_status === \App\Enums\PublisherPaymentProfileStatus::Verified)
        <p class="muted">Changing the beneficiary, method, currency, country, address, account, routing, or tax destination will reset verification and require Finance review before payout.</p>
    @endif
    @if(auth()->user()->hasPermission('finance.publisher.payment_profile.manage'))
        <form method="POST" action="{{ route('publisher.finance.payment-method.update') }}" class="form-grid" autocomplete="off">
            @csrf @method('PUT')
            <label>Beneficiary name<input class="hm-input" name="beneficiary_name" value="{{ old('beneficiary_name', $profile?->beneficiary_name) }}" required></label>
            <label>Payment method<select class="hm-input" name="payment_method">@foreach(['BANK_TRANSFER','PAYPAL','WISE','OTHER'] as $method)<option value="{{ $method }}" @selected(old('payment_method', $profile?->payment_method) === $method)>{{ str($method)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
            <label>Currency<input class="hm-input" name="currency" value="{{ old('currency', $profile?->currency ?: 'USD') }}" maxlength="3" required></label>
            <label>Country<input class="hm-input" name="country" value="{{ old('country', $profile?->country) }}" maxlength="2" required></label>
            <label class="full">Billing address<input class="hm-input" name="billing_address" value="{{ old('billing_address', $profile?->billing_address) }}"></label>
            <label>Replacement account/payment reference<input class="hm-input" name="account_reference" value="" autocomplete="new-password" aria-describedby="account-help"><span id="account-help" class="muted">Leave blank to retain {{ $profile?->maskedAccountReference() ?: 'no saved reference' }}.</span></label>
            <label>Replacement routing/SWIFT information<input class="hm-input" name="routing_reference" value="" autocomplete="new-password"></label>
            <label>Replacement tax information<input class="hm-input" name="tax_identifier" value="" autocomplete="new-password"></label>
            <button class="hm-button-primary">Save payment method</button>
        </form>
    @else
        <p class="muted">Your role can view this payment profile but cannot change it.</p>
    @endif
</article>
@endsection
