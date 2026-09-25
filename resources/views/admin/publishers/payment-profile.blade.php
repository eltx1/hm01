@extends('layouts.admin')
@section('title', 'Publisher payment profile')
@section('heading', $publisher->display_name.' · Payment details')
@section('content')
<x-payment-profile-editor :profile="$profile" :action="route('admin.publishers.payment-profile.update', $publisher)" :cancel-href="auth()->user()->hasPermission('finance.payment_profiles.verify') ? route('admin.finance.payment-profiles.index') : route('admin.publishers.show', $publisher)" />
@if($profile && auth()->user()->hasPermission('finance.payment_profiles.verify'))
<div class="ui-page workspace-section">
    <form method="POST" action="{{ route('admin.publishers.payment-profile.review', $publisher) }}" class="ui-form-surface">
        @csrf
        <x-form-section title="Finance review" description="Record a separate verification decision for this payment destination.">
            <div class="ui-fields">
                <x-form-field name="verification_status" label="Decision" as="select" :value="old('verification_status', $profile->verification_status?->value)" :options="['PENDING_VERIFICATION' => 'Keep under review', 'VERIFIED' => 'Verify payment details', 'REJECTED' => 'Request an update']" placeholder="Choose a review decision" required />
                <x-form-field name="verification_reason" label="Feedback for the publisher" as="textarea" :value="old('verification_reason', $profile->verification_reason)" maxlength="1000" rows="3" help="Required when requesting an update. Do not include private bank or tax details." />
            </div>
        </x-form-section>
        <x-form-actions help="This decision is recorded in the audit history."><button class="hm-button-primary" type="submit">Record verification decision</button></x-form-actions>
    </form>
</div>
@endif
@endsection
