@extends('layouts.applicant')
@section('title', 'Publisher Application')
@section('content')
@php
    $status = $application->status;
    $requestEvent = $application->events->firstWhere('action', 'MORE_INFO_REQUESTED');
    $rejectionEvent = $application->events->firstWhere('action', 'REJECTED');
    $requiredDocuments = collect($legalDocuments)->where('required', true);
    $agreementsComplete = $requiredDocuments->every(fn ($document) => $acceptedLegal->has($document['type'].'@'.$document['version']));
    $progress = match (true) {
        !auth()->user()->hasVerifiedEmail() => 20,
        $status === \App\Enums\PublisherApplicationStatus::Approved => 100,
        $status === \App\Enums\PublisherApplicationStatus::Submitted, $status === \App\Enums\PublisherApplicationStatus::UnderReview, $status === \App\Enums\PublisherApplicationStatus::Rejected, $status === \App\Enums\PublisherApplicationStatus::Withdrawn => 100,
        $profile && $agreementsComplete => 90,
        $profile => 75,
        $claimVerified => 55,
        $websiteVerification => 45,
        default => 40,
    };
@endphp
@if($expressApplication)
    @include('publisher-applications.express')
@else
<section class="hero publisher-application-hero">
    <div><p class="eyebrow">Publisher application</p><h1>{{ $application->publisher->display_name }}</h1><p>{{ $application->primary_domain }}</p></div>
    <div class="application-status-summary"><x-status-badge :status="$status" /><span class="status">{{ $progress }}% complete</span><small class="muted">Last saved {{ $application->updated_at?->diffForHumans() }}</small></div>
</section>

<ol class="publisher-application-steps" aria-label="Publisher application progress">
@foreach([1 => 'Account', 2 => 'Website / Verify', 3 => 'Quality & traffic', 4 => 'Agreements', 5 => 'Review & submit'] as $number => $label)
    @php($accessible = $number <= 2 || $claimVerified)
    <li @class(['active' => $step === $number, 'complete' => $number === 1 ? auth()->user()->hasVerifiedEmail() : ($number === 2 ? $claimVerified : ($number === 3 ? (bool) $profile : ($number === 4 ? $agreementsComplete : false)))])>
        @if($number > 1 && $editable && $accessible)<a href="{{ route('publisher-application.show', ['step' => $number]) }}"><span>{{ $number }}</span><strong>{{ $label }}</strong></a>@else<span>{{ $number }}</span><strong>{{ $label }}</strong>@endif
    </li>
@endforeach
</ol>

<section class="application-meta" aria-label="Application information"><div><span>Application status</span><strong>{{ str($status->value)->replace('_', ' ')->headline() }}</strong></div><div><span>Website verification</span><strong>{{ $application->domainClaim?->verification_status ?? 'NOT STARTED' }}</strong></div><div><span>Submitted revision</span><strong>{{ $application->current_revision ?: 'Not submitted' }}</strong></div></section>

@if($status === \App\Enums\PublisherApplicationStatus::MoreInfoRequired && $requestEvent)
<article class="workspace-section"><p class="eyebrow">Additional information requested</p><h2>Horus Media needs an update</h2><p>{{ $requestEvent->reason }}</p><p class="muted">Update the requested information and resubmit. Your earlier submitted revisions and evidence remain immutable.</p></article>
@endif

@if($step === 1)
<article class="workspace-section wizard-card"><p class="eyebrow">Step 1 of 5</p><h2>Verify your account</h2><p>Your application is saved, but it cannot be submitted until your business email is verified.</p><p><strong>{{ auth()->user()->email }}</strong></p><form method="POST" action="{{ route('verification.send') }}">@csrf<button class="hm-button-primary">Send another verification link</button></form></article>
@endif

@if($editable && $step === 2)
<article class="workspace-section wizard-card">
    <p class="eyebrow">Step 2 of 5</p><h2>Website and Publisher</h2>
    <p class="muted">Confirm who operates the property. Saving this step reserves the permanent Horus Publisher and Website seller IDs used for ads.txt verification. This does not approve the Publisher or activate monetization.</p>
    <form method="POST" action="{{ route('publisher-application.update') }}" class="form-grid">@csrf @method('PUT')<input type="hidden" name="step" value="2">
        <label>Contact name<input class="hm-input" name="contact_name" value="{{ old('contact_name', auth()->user()->name) }}" required maxlength="255"></label>
        <label>Business email<input class="hm-input" value="{{ auth()->user()->email }}" disabled></label>
        <label>Legal business name<input class="hm-input" name="legal_name" value="{{ old('legal_name', $application->publisher->legal_name) }}" required maxlength="255"></label>
        <label>Publisher display name<input class="hm-input" name="publisher_name" value="{{ old('publisher_name', $application->publisher->display_name) }}" required maxlength="255"></label>
        <label class="full">Primary website or domain<input class="hm-input" name="primary_domain" value="{{ old('primary_domain', $application->primary_domain) }}" required maxlength="500" @readonly((bool) $websiteVerification)></label>
        @if($websiteVerification)<p class="field-help full">This domain is locked because its permanent HMS seller identity has already been reserved.</p>@endif
        <div class="full wizard-actions"><span></span><button class="hm-button-primary">{{ $websiteVerification ? 'Save Publisher details' : 'Save & prepare verification' }}</button></div>
    </form>
</article>

@if($websiteVerification)
<article class="workspace-section wizard-card website-verification-card">
    <p class="eyebrow">Website Verification</p><h2>{{ $claimVerified ? '✓ Website verified' : 'Verify your website with ads.txt' }}</h2>
    <p>Add these two real Horus seller authorizations to your website's ads.txt file. Keep both lines exactly as shown; normal standards-valid whitespace is accepted.</p>
    <pre id="application-ads-txt-records">{{ implode("\n", $websiteVerification['records']) }}</pre>
    <div class="wizard-actions"><button type="button" data-copy-target="application-ads-txt-records">Copy Both</button><span></span></div>
    <div class="detail-grid">
        <div><span>Expected location</span><strong>{{ $websiteVerification['ads_txt_url'] }}</strong></div>
        <div><span>Publisher Seller ID</span><strong>{{ $websiteVerification['publisher_seller']->seller_id }}</strong></div>
        <div><span>Website Seller ID</span><strong>{{ $websiteVerification['website_seller']->seller_id }}</strong></div>
        <div><span>Status</span><strong>{{ $application->domainClaim?->verification_status ?? 'PENDING' }}</strong></div>
    </div>
    @if($application->domainClaim?->last_checked_at)<p class="muted">Last checked {{ $application->domainClaim->last_checked_at }}@if($application->domainClaim->failure_code) · {{ $application->domainClaim->failure_code }}@endif</p>@endif
    @error('website_verification')<p class="field-error" role="alert">{{ $message }}</p>@enderror
    @if($claimVerified)
        <div class="wizard-actions"><span class="status">✓ Website verified</span><a class="hm-button-primary button-link" href="{{ route('publisher-application.show', ['step' => 3]) }}">Continue to quality</a></div>
    @else
        <form method="POST" action="{{ route('publisher-application.update') }}" class="wizard-actions">@csrf @method('PUT')<input type="hidden" name="step" value="2"><input type="hidden" name="verify_website" value="1"><span class="muted">Horus checks the live ads.txt file only.</span><button class="hm-button-primary" data-submitting-label="Verifying…">Verify Website</button></form>
    @endif
</article>
@endif
@endif

@if($editable && $step === 3)
<article class="workspace-section wizard-card"><p class="eyebrow">Step 3 of 5</p><h2>Quality and traffic information</h2><p class="muted">This populates the canonical Publisher Quality Profile used by Horus review and THOTH advisory. It does not create a second traffic profile.</p><form method="POST" action="{{ route('publisher-application.update') }}" class="form-grid">@csrf @method('PUT')<input type="hidden" name="step" value="3">
<label>Content categories<select class="hm-input" name="content_categories[]" multiple required>@foreach(['NEWS','ENTERTAINMENT','SPORTS','TECHNOLOGY','LIFESTYLE','BUSINESS','OTHER'] as $category)<option @selected(in_array($category, old('content_categories', $profile?->content_categories ?? [])))>{{ $category }}</option>@endforeach</select></label>
<label>Content description<textarea class="hm-input" name="content_description" required maxlength="10000">{{ old('content_description', $profile?->content_description) }}</textarea></label>
<label>Estimated monthly pageviews<input class="hm-input" type="number" name="monthly_pageviews" min="0" value="{{ old('monthly_pageviews', data_get($profile?->traffic_profile, 'monthly_pageviews')) }}"></label>
@foreach(['organic','social','direct','paid','other'] as $source)<label>{{ str($source)->headline() }} traffic %<input class="hm-input" type="number" name="{{ $source }}_percent" min="0" max="100" required value="{{ old($source.'_percent', data_get($profile?->traffic_profile, $source, $source === 'direct' ? 100 : 0)) }}"></label>@endforeach
<label>Audience countries<select class="hm-input" name="audience_countries[]" multiple required>@foreach(['US','GB','CA','AU','AE','SA','EG','FR','DE','IN','OTHER'] as $country)<option @selected(in_array($country, old('audience_countries', $profile?->audience_countries ?? [])))>{{ $country }}</option>@endforeach</select></label>
@foreach(['desktop','mobile','tablet'] as $device)<label>{{ str($device)->headline() }} %<input class="hm-input" type="number" name="{{ $device }}_percent" min="0" max="100" required value="{{ old($device.'_percent', data_get($profile?->device_mix, $device, $device === 'desktop' ? 100 : 0)) }}"></label>@endforeach
@foreach(['original_content','user_generated_content','ai_assisted_content','sensitive_content','has_privacy_policy','has_contact_details','has_cmp','prior_policy_incidents'] as $flag)<label class="check"><input type="hidden" name="{{ $flag }}" value="0"><input type="checkbox" name="{{ $flag }}" value="1" @checked(old($flag, data_get($profile?->declarations, $flag, false)))> {{ str($flag)->replace('_', ' ')->headline() }}</label>@endforeach
<label class="full">Monetization history<textarea class="hm-input" name="monetization_history" maxlength="5000">{{ old('monetization_history', data_get($profile?->traffic_profile, 'monetization_history')) }}</textarea></label>
<label class="full">Additional application notes<textarea class="hm-input" name="application_notes" maxlength="5000">{{ old('application_notes', $profile?->review_comments) }}</textarea></label>
<div class="full wizard-actions"><a class="text-link" href="{{ route('publisher-application.show', ['step' => 2]) }}">Back</a><button class="hm-button-primary">Save & continue</button></div></form></article>
@endif

@if($editable && $step === 4)
<article class="workspace-section wizard-card"><p class="eyebrow">Step 4 of 5</p><h2>Agreements</h2><p class="muted">Required application documents are versioned and configured by Horus Media. Accepting a new version never overwrites evidence of a version you accepted earlier.</p><form method="POST" action="{{ route('publisher-application.update') }}" class="form-stack">@csrf @method('PUT')<input type="hidden" name="step" value="4">
@forelse($legalDocuments as $type => $document)
    @php($accepted = $acceptedLegal->has($type.'@'.$document['version']))
    <label class="agreement-row"><input type="checkbox" name="legal[{{ $type }}]" value="1" @checked(old('legal.'.$type, $accepted)) @required($document['required'])><span><strong>{{ $document['label'] }}</strong> · version {{ $document['version'] }} @if($document['required'])<em>Required</em>@else<em>Optional</em>@endif<br><a class="text-link" href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer">Read current document</a>@if($accepted)<small class="muted"> · already accepted</small>@endif</span></label>
@empty
    <div class="notice">No public legal documents are currently configured for acceptance. Deployment must supply official document URLs and version identifiers; Horus does not invent legal text.</div>
@endforelse
<hr class="application-divider">
<label class="agreement-row marketing-consent"><input type="hidden" name="marketing_opt_in" value="0"><input type="checkbox" name="marketing_opt_in" value="1" @checked(old('marketing_opt_in', $marketingConsent?->opted_in ?? false))><span><strong>Optional marketing updates</strong><br><span class="muted">Send me optional Horus Media marketing updates. This is unchecked by default and is not required for the application or transactional emails.</span></span></label>
<div class="wizard-actions"><a class="text-link" href="{{ route('publisher-application.show', ['step' => 3]) }}">Back</a><button class="hm-button-primary">Record choices & continue</button></div></form></article>
@endif

@if($step === 5)
<article class="workspace-section wizard-card"><p class="eyebrow">Step 5 of 5</p><h2>Review and submit</h2>
<div class="summary-grid"><div><span>Applicant</span><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div><div><span>Publisher</span><strong>{{ $application->publisher->display_name }}</strong><small>{{ $application->primary_domain }}</small></div><div><span>Website verification</span><strong>{{ $application->domainClaim?->verification_status ?? 'NOT STARTED' }}</strong><small>{{ $application->domainClaim?->verified_at ?: 'Verification required before submission.' }}</small></div><div><span>Quality profile</span><strong>{{ $profile ? 'Version '.$profile->version : 'Not completed' }}</strong><small>{{ $profile?->content_description ?: 'Complete Step 3 before submission.' }}</small></div><div><span>Required agreements</span><strong>{{ $agreementsComplete ? 'Current versions accepted' : 'Action required' }}</strong><small>{{ $requiredDocuments->count() }} current required document(s)</small></div></div>
@if($editable)<form method="POST" action="{{ route('publisher-application.submit') }}" class="form-stack application-submit">@csrf<label class="check"><input type="checkbox" name="confirm" value="1" required> I confirm the submitted application information is complete and accurate.</label><p class="muted">Submission creates an immutable application revision. Website verification is supply-chain evidence only. Horus staff remain the final decision-maker; THOTH advisory cannot approve or reject the application.</p><div class="wizard-actions"><a class="text-link" href="{{ route('publisher-application.show', ['step' => 4]) }}">Back</a><button class="hm-button-primary">{{ $status === \App\Enums\PublisherApplicationStatus::MoreInfoRequired ? 'Resubmit for review' : 'Submit for review' }}</button></div></form>@else<p class="muted">Submitted evidence is read-only unless Horus Media requests additional information.</p>@endif
</article>
@endif

@if($status === \App\Enums\PublisherApplicationStatus::Approved)
<article class="workspace-section"><p class="eyebrow">Approved application</p><h2>Continue to Publisher onboarding</h2><p>Publisher approval does not approve a website or activate ad serving. Your reserved HMP and HMS seller IDs remain the same.</p><a class="hm-button-primary button-link" href="{{ route('publisher.onboarding.show', 1) }}">Continue onboarding</a></article>
@elseif($status === \App\Enums\PublisherApplicationStatus::Rejected)
<article class="workspace-section"><h2>Application not approved</h2><p>This application was not approved. Your account remains limited to this status experience and cannot create serving configuration. Reserved Horus seller IDs are retained permanently and remain disabled; remove their records from your ads.txt.</p>@if($rejectionEvent?->reason)<p>{{ $rejectionEvent->reason }}</p>@endif</article>
@elseif($status === \App\Enums\PublisherApplicationStatus::Withdrawn)
<article class="workspace-section"><h2>Application withdrawn</h2><p>No operational access was granted. Security, audit, legal, seller identity, and website verification evidence has been retained. Remove the Horus records from your ads.txt if they are no longer needed.</p></article>
@endif

@if($profile)<article class="workspace-section saved-quality-evidence"><p class="eyebrow">Saved quality profile</p><h2>Current application evidence</h2><p>{{ $profile->content_description }}</p><span class="status">Profile v{{ $profile->version }}</span></article>@endif
<article class="workspace-section"><h2>Submitted revisions</h2>@forelse($application->revisions as $revision)<div class="compact-row"><div><strong>Revision {{ $revision->version }}</strong><p>Evidence hash {{ $revision->snapshot_hash }}</p></div><span class="muted">{{ $revision->submitted_at }}</span></div>@empty<p class="muted">No application revision has been submitted.</p>@endforelse</article>

@if($status->canTransitionTo(\App\Enums\PublisherApplicationStatus::Withdrawn))
<details class="workspace-section"><summary>Withdraw application</summary><p>Withdrawal does not delete audit, legal, seller identity, or website verification evidence.</p><form method="POST" action="{{ route('publisher-application.withdraw') }}" class="form-stack">@csrf<label class="check"><input type="checkbox" name="confirm_withdrawal" value="1" required> I confirm that I want to withdraw this application.</label><button class="danger-button">Withdraw application</button></form></details>
@endif
@endif
@endsection
