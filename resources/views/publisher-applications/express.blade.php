<section class="hero publisher-application-hero express-application-hero">
    <div>
        <p class="eyebrow">Publisher application</p>
        <h1>{{ $application->publisher->display_name }}</h1>
        <p>Publisher approval is independent from website approval.</p>
    </div>
    <div class="application-status-summary">
        <x-status-badge :status="$status" />
        <small class="muted">Websites are added separately after approval</small>
    </div>
</section>

@if($status === \App\Enums\PublisherApplicationStatus::MoreInfoRequired && $requestEvent)
    <div class="notice error"><strong>Update requested:</strong> {{ $requestEvent->reason }}</div>
@endif

@if($editable)
<article class="workspace-section wizard-card express-application-card">
    <div class="express-heading">
        <div><p class="eyebrow">Final step</p><h2>Company details and submit</h2></div>
        <span class="status">About 2 minutes</span>
    </div>
    <p class="muted">No website, ads.txt, traffic data, or technical setup is required here.</p>
    <form method="POST" action="{{ route('publisher-application.complete') }}" class="form-grid express-application-form">
        @csrf
        <label>Contact name<input class="hm-input" name="contact_name" value="{{ old('contact_name', auth()->user()->name) }}" required maxlength="255"></label>
        <label>Legal business name<input class="hm-input" name="legal_name" value="{{ old('legal_name', $application->publisher->legal_name) }}" required maxlength="255"></label>
        <label>Publisher display name<input class="hm-input" name="publisher_name" value="{{ old('publisher_name', $application->publisher->display_name) }}" required maxlength="255"></label>
        <label>Primary content category
            <select class="hm-input" name="content_categories[]" required>
                <option value="">Choose one</option>
                @foreach(['NEWS','ENTERTAINMENT','SPORTS','TECHNOLOGY','LIFESTYLE','BUSINESS','OTHER'] as $category)
                    <option value="{{ $category }}" @selected(in_array($category, old('content_categories', $profile?->content_categories ?? [])))>{{ str($category)->headline() }}</option>
                @endforeach
            </select>
        </label>
        <label class="full">What do you publish?
            <textarea class="hm-input express-description" name="content_description" required minlength="20" maxlength="2000" rows="3" placeholder="One or two sentences are enough.">{{ old('content_description', $profile?->content_description) }}</textarea>
        </label>

        <div class="full express-agreements">
            <p class="eyebrow">Required agreements</p>
            @forelse($legalDocuments as $type => $document)
                @php($accepted = $acceptedLegal->has($type.'@'.$document['version']))
                <label class="agreement-row compact-agreement">
                    <input type="checkbox" name="legal[{{ $type }}]" value="1" @checked(old('legal.'.$type, $accepted)) @required($document['required'])>
                    <span><strong>{{ $document['label'] }}</strong> @if($document['required'])<em>Required</em>@endif · <a class="text-link" href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer">Read</a></span>
                </label>
            @empty
                <div class="notice error">Required legal documents are not configured. Horus Media must configure them before applications can be submitted.</div>
            @endforelse
            <label class="agreement-row compact-agreement marketing-consent"><input type="hidden" name="marketing_opt_in" value="0"><input type="checkbox" name="marketing_opt_in" value="1" @checked(old('marketing_opt_in', $marketingConsent?->opted_in ?? false))><span>Send me optional product updates</span></label>
        </div>

        <label class="check full express-confirm"><input type="checkbox" name="confirm" value="1" required> I confirm these details are accurate.</label>
        <div class="full wizard-actions"><span class="muted">You will add and verify websites after Publisher approval.</span><button class="hm-button-primary" data-submitting-label="Submitting…">Submit application</button></div>
    </form>
</article>
@else
<article class="workspace-section wizard-card express-status-card">
    @if(in_array($status, [\App\Enums\PublisherApplicationStatus::Submitted, \App\Enums\PublisherApplicationStatus::UnderReview], true))
        <p class="eyebrow">Application received</p><h2>We are reviewing your Publisher account</h2>
        <p>Your account application is complete. No website is approved or rejected as part of this decision.</p>
    @elseif($status === \App\Enums\PublisherApplicationStatus::Approved)
        <p class="eyebrow">Approved</p><h2>Your Publisher account is ready</h2>
        <p>Add your first website from the dashboard. Each website gets its own review and one complete ads.txt installation block.</p>
        <a class="hm-button-primary button-link" href="{{ route('publisher.sites.create') }}">Add first website</a>
    @elseif($status === \App\Enums\PublisherApplicationStatus::Rejected)
        <p class="eyebrow">Decision complete</p><h2>Application not approved</h2><p>{{ $rejectionEvent?->reason ?: 'Contact Horus Media if you need clarification.' }}</p>
    @elseif($status === \App\Enums\PublisherApplicationStatus::Withdrawn)
        <p class="eyebrow">Withdrawn</p><h2>Application withdrawn</h2>
    @endif
</article>
@endif

@if($status->canTransitionTo(\App\Enums\PublisherApplicationStatus::Withdrawn))
<details class="workspace-section express-withdraw"><summary>Withdraw application</summary><form method="POST" action="{{ route('publisher-application.withdraw') }}" class="form-stack">@csrf<label class="check"><input type="checkbox" name="confirm_withdrawal" value="1" required> Confirm withdrawal</label><button class="danger-button">Withdraw</button></form></details>
@endif
