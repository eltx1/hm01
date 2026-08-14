@extends('layouts.applicant')
@section('title', 'Publisher Application')
@section('content')
@php
    $status = $application->status;
    $editable = auth()->user()->hasVerifiedEmail() && $status->applicantMayEdit();
    $requestEvent = $application->events->firstWhere('action', 'MORE_INFO_REQUESTED');
    $rejectionEvent = $application->events->firstWhere('action', 'REJECTED');
@endphp
<section class="hero">
    <div><p class="eyebrow">Application status</p><h1>{{ $application->publisher->display_name }}</h1><p>{{ $application->primary_domain }}</p></div>
    <div class="status-row"><x-status-badge :status="$status" /><span class="status">Revision {{ $application->current_revision ?: 'not submitted' }}</span></div>
</section>

<section class="lifecycle-boundaries" aria-label="Publisher lifecycle boundaries">
    <article><strong>1. Public application</strong><span class="muted">Identity and business review</span></article>
    <article><strong>2. Publisher approval</strong><span class="muted">Operational account eligibility</span></article>
    <article><strong>3. Website approval</strong><span class="muted">Separate onboarding review</span></article>
    <article><strong>4. Production monetization</strong><span class="muted">Separate activation and serving controls</span></article>
</section>

@unless(auth()->user()->hasVerifiedEmail())
<article class="workspace-section"><h2>Verify your email</h2><p>Your draft cannot be submitted until your business email is verified.</p><form method="POST" action="{{ route('verification.send') }}">@csrf<button>Send another verification link</button></form></article>
@endunless

@if($status === \App\Enums\PublisherApplicationStatus::MoreInfoRequired && $requestEvent)
<article class="workspace-section"><p class="eyebrow">Additional information requested</p><h2>Horus Media needs an update</h2><p>{{ $requestEvent->reason }}</p><p class="muted">Update the allowed fields below, save the draft, then resubmit. Your previous submitted revision remains unchanged.</p></article>
@endif

@if($status === \App\Enums\PublisherApplicationStatus::Approved)
<article class="workspace-section"><p class="eyebrow">Approved application</p><h2>Continue to Publisher onboarding</h2><p>Publisher approval does not approve a website or activate ad serving.</p><a class="hm-button-primary button-link" href="{{ route('publisher.onboarding.show', 1) }}">Continue onboarding</a></article>
@elseif($status === \App\Enums\PublisherApplicationStatus::Rejected)
<article class="workspace-section"><h2>Review completed</h2><p>This application was not approved. Your account remains limited to this status experience and cannot create serving configuration.</p>@if($rejectionEvent?->reason)<p>{{ $rejectionEvent->reason }}</p>@endif</article>
@elseif($status === \App\Enums\PublisherApplicationStatus::Withdrawn)
<article class="workspace-section"><h2>Application withdrawn</h2><p>No operational access was granted. Security, audit, and submitted revision evidence has been retained.</p></article>
@endif

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Save and resume</p><h2>Application details</h2></div>@if($profile)<span class="status">Profile v{{ $profile->version }}</span>@endif</div>
    @if($editable)
    <form method="POST" action="{{ route('publisher-application.update') }}" class="form-grid">@csrf @method('PUT')
        <label>Contact name<input name="contact_name" value="{{ old('contact_name', auth()->user()->name) }}" required maxlength="255"></label>
        <label>Verified business email<input value="{{ auth()->user()->email }}" disabled></label>
        <label>Legal business name<input name="legal_name" value="{{ old('legal_name', $application->publisher->legal_name) }}" required maxlength="255"></label>
        <label>Publisher display name<input name="publisher_name" value="{{ old('publisher_name', $application->publisher->display_name) }}" required maxlength="255"></label>
        <label class="full">Primary website or domain<input name="primary_domain" value="{{ old('primary_domain', $application->primary_domain) }}" required maxlength="500"></label>
        <label>Content categories<select name="content_categories[]" multiple>@foreach(['NEWS','ENTERTAINMENT','SPORTS','TECHNOLOGY','LIFESTYLE','BUSINESS','OTHER'] as $category)<option @selected(in_array($category, old('content_categories', $profile?->content_categories ?? [])))>{{ $category }}</option>@endforeach</select></label>
        <label>Content description<textarea name="content_description" maxlength="10000">{{ old('content_description', $profile?->content_description) }}</textarea></label>
        <label>Estimated monthly pageviews<input type="number" name="monthly_pageviews" min="0" value="{{ old('monthly_pageviews', data_get($profile?->traffic_profile, 'monthly_pageviews')) }}"></label>
        @foreach(['organic','social','direct','paid','other'] as $source)<label>{{ str($source)->headline() }} traffic %<input type="number" name="{{ $source }}_percent" min="0" max="100" value="{{ old($source.'_percent', data_get($profile?->traffic_profile, $source, $source === 'direct' ? 100 : 0)) }}"></label>@endforeach
        <label>Audience countries<select name="audience_countries[]" multiple>@foreach(['US','GB','CA','AU','AE','SA','EG','FR','DE','IN','OTHER'] as $country)<option @selected(in_array($country, old('audience_countries', $profile?->audience_countries ?? [])))>{{ $country }}</option>@endforeach</select></label>
        @foreach(['desktop','mobile','tablet'] as $device)<label>{{ str($device)->headline() }} %<input type="number" name="{{ $device }}_percent" min="0" max="100" value="{{ old($device.'_percent', data_get($profile?->device_mix, $device, $device === 'desktop' ? 100 : 0)) }}"></label>@endforeach
        @foreach(['original_content','user_generated_content','ai_assisted_content','sensitive_content','has_privacy_policy','has_contact_details','has_cmp','prior_policy_incidents'] as $flag)<label><input type="hidden" name="{{ $flag }}" value="0"><input type="checkbox" name="{{ $flag }}" value="1" @checked(old($flag, data_get($profile?->declarations, $flag, false)))> {{ str($flag)->replace('_', ' ')->headline() }}</label>@endforeach
        <label class="full">Monetization history<textarea name="monetization_history" maxlength="5000">{{ old('monetization_history', data_get($profile?->traffic_profile, 'monetization_history')) }}</textarea></label>
        <label class="full">Additional application notes<textarea name="application_notes" maxlength="5000">{{ old('application_notes', $profile?->review_comments) }}</textarea></label>
        <button class="hm-button-primary" type="submit">Save draft</button>
    </form>
    @else<p class="muted">Submitted evidence is read-only until Horus Media requests more information.</p>@endif
</article>

@if($editable)
<article class="workspace-section"><h2>{{ $status === \App\Enums\PublisherApplicationStatus::MoreInfoRequired ? 'Resubmit application' : 'Submit application' }}</h2><p>Submission validates the current saved quality-profile version atomically and creates an immutable evidence revision.</p><form method="POST" action="{{ route('publisher-application.submit') }}" class="form-stack">@csrf<label class="check"><input type="checkbox" name="confirm" value="1" required> I confirm this application is complete and accurate.</label><button class="hm-button-primary">{{ $status === \App\Enums\PublisherApplicationStatus::MoreInfoRequired ? 'Resubmit for review' : 'Submit for review' }}</button></form></article>
@endif

<article class="workspace-section"><h2>Submitted revisions</h2>@forelse($application->revisions as $revision)<div class="compact-row"><div><strong>Revision {{ $revision->version }}</strong><p>Evidence hash {{ $revision->snapshot_hash }}</p></div><span class="muted">{{ $revision->submitted_at }}</span></div>@empty<p class="muted">No application revision has been submitted.</p>@endforelse</article>

@if($status->canTransitionTo(\App\Enums\PublisherApplicationStatus::Withdrawn))
<details class="workspace-section"><summary>Withdraw application</summary><p>Withdrawal does not delete audit or security evidence.</p><form method="POST" action="{{ route('publisher-application.withdraw') }}" class="form-stack">@csrf<label class="check"><input type="checkbox" name="confirm_withdrawal" value="1" required> I confirm that I want to withdraw this application.</label><button class="danger-button">Withdraw application</button></form></details>
@endif
@endsection
