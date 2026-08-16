@extends('layouts.admin')

@section('title', 'Publisher Application')
@section('heading', 'Publisher Application Review')

@section('content')
@php
    $profile = $application->publisher->qualityProfiles->first();
    $latestMarketingConsent = $application->marketingConsents->first();
    $canReview = auth()->user()->hasPermission('publisher_applications.review');
    $canRunThoth = auth()->user()->hasPermission('publisher_quality.ai.run');
    $claim = $application->domainClaims->firstWhere('claim_status', 'CLAIMED') ?? $application->domainClaims->first();
    $freshDays = max(1, (int) config('thoth.application_domain_verification_fresh_days', 7));
    $claimFresh = $claim?->verification_status === 'VERIFIED' && $claim?->verified_at?->greaterThanOrEqualTo(now()->subDays($freshDays));
    $applicationRun = $application->publisher->qualityReviewRuns->first(fn ($run) =>
        ($run->evidence_snapshot['review_context'] ?? null) === 'PUBLISHER_APPLICATION'
        && ($run->evidence_snapshot['application']['id'] ?? null) === $application->id
    );
    $applicationEvidence = $applicationRun?->evidence_snapshot ?? [];
    $websiteEvidence = $applicationEvidence['website_evidence'] ?? [];
    $evidenceGaps = $applicationEvidence['evidence_gaps'] ?? [];
    $advisory = $applicationRun?->result ?? [];
    $reviewableForThoth = in_array($application->status, [
        \App\Enums\PublisherApplicationStatus::Submitted,
        \App\Enums\PublisherApplicationStatus::UnderReview,
        \App\Enums\PublisherApplicationStatus::MoreInfoRequired,
    ], true);
@endphp

<section class="hero">
    <div>
        <p class="eyebrow">{{ $application->applicant->name }} · {{ $application->applicant->email }}</p>
        <h2>{{ $application->publisher->display_name }}</h2>
        <p>{{ $application->publisher->legal_name }} · {{ $application->primary_domain }}</p>
    </div>
    <div class="status-row">
        <x-status-badge :status="$application->status" />
        <span class="status">Revision {{ $application->current_revision ?: 'not submitted' }}</span>
    </div>
</section>

<div class="notice">
    <strong>Decision boundary:</strong> ads.txt verification is website/supply-chain evidence only. THOTH is an AI advisor only. Application approval, website approval and production monetization remain separate human-controlled states.
</div>

<section class="metric-grid">
    <article><p class="eyebrow">Created</p><strong>{{ $application->created_at }}</strong></article>
    <article><p class="eyebrow">First submitted</p><strong>{{ $application->submitted_at ?: 'Not submitted' }}</strong></article>
    <article><p class="eyebrow">Last submitted</p><strong>{{ $application->last_submitted_at ?: 'Not submitted' }}</strong></article>
    <article><p class="eyebrow">Email</p><strong>{{ $application->applicant->hasVerifiedEmail() ? 'Verified' : 'Not verified' }}</strong></article>
    <article><p class="eyebrow">Publisher account</p><strong>{{ $application->publisher->status->value }}</strong></article>
</section>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Domain / authorization</p><h2>Website verification &amp; Horus seller identities</h2></div>@if($claim)<span class="status">{{ $claim->verification_status }}</span>@endif</div>
    @if($claim)
        <div class="detail-grid">
            <div><span>Website domain</span><strong>{{ $claim->normalized_domain }}</strong></div>
            <div><span>Publisher Seller ID</span><strong>{{ $claim->publisherSeller?->seller_id ?: 'Not reserved' }}</strong></div>
            <div><span>Website Seller ID</span><strong>{{ $claim->websiteSeller?->seller_id ?: 'Not reserved' }}</strong></div>
            <div><span>Horus ads.txt verification</span><strong>{{ $claim->verification_status }}</strong></div>
            <div><span>Verification timestamp</span><strong>{{ $claim->verified_at ?: 'Not verified' }}</strong></div>
            <div><span>Freshness</span><strong>{{ $claimFresh ? 'Fresh' : ($claim->verification_status === 'VERIFIED' ? 'Stale — THOTH will re-check before fetching' : 'Not verified') }}</strong></div>
            <div><span>Last checked</span><strong>{{ $claim->last_checked_at ?: 'Never' }}</strong></div>
            <div><span>Final ads.txt URL</span><strong>{{ $claim->final_ads_txt_url ?: 'Not fetched' }}</strong></div>
            <div><span>HTTP evidence</span><strong>{{ $claim->verification_http_status ?: '—' }} · {{ $claim->verification_content_type ?: '—' }}</strong></div>
            <div><span>Evidence SHA-256</span><strong>{{ $claim->evidence_sha256 ?: '—' }}</strong></div>
            <div><span>Failure code</span><strong>{{ $claim->failure_code ?: 'None' }}</strong></div>
        </div>
        <p class="muted">HMP/HMS are permanent public technical identifiers displayed to Admin for supply-chain review. THOTH receives only the semantic authorization result, not these seller IDs.</p>
    @else
        <p class="muted">No application domain claim is available. THOTH will not fetch an arbitrary or merely submitted website.</p>
    @endif
</article>

@if ($canReview)
    <article class="workspace-section">
        <div class="workspace-heading"><div><p class="eyebrow">Human decision</p><h2>Application review actions</h2></div><span class="status">Admin only</span></div>
        <p class="muted">These are the authoritative application actions. THOTH cannot call or trigger them.</p>
        <div class="status-row">
            @if ($application->status === \App\Enums\PublisherApplicationStatus::Submitted)
                <form method="POST" action="{{ route('admin.publisher-applications.start-review', $application) }}">@csrf<button class="hm-button-primary">Start review</button></form>
            @endif

            @if ($application->status === \App\Enums\PublisherApplicationStatus::UnderReview)
                <details><summary>Request more information</summary><form method="POST" action="{{ route('admin.publisher-applications.request-information', $application) }}" class="form-stack">@csrf<label>Required applicant-visible request<textarea name="reason" required maxlength="5000"></textarea></label><button>Send information request</button></form></details>
                <form method="POST" action="{{ route('admin.publisher-applications.approve', $application) }}">@csrf<button class="hm-button-primary">Approve application</button></form>
                <details><summary>Reject</summary><form method="POST" action="{{ route('admin.publisher-applications.reject', $application) }}" class="form-stack">@csrf<label>Required decision reason<textarea name="reason" required maxlength="5000"></textarea></label><button class="danger-button">Reject application</button></form></details>
            @endif

            @if ($application->status === \App\Enums\PublisherApplicationStatus::Approved)
                <a class="hm-button-primary button-link" href="{{ route('admin.publishers.show', $application->publisher) }}">Open Publisher 360</a>
            @endif
        </div>
    </article>
@endif

<article class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Canonical Task 24 profile</p><h2>Publisher quality evidence</h2></div>
        @if ($profile)<span class="status">Version {{ $profile->version }}</span>@endif
    </div>
    <p class="muted">THOTH may append advisory evidence but can never approve or reject this application.</p>

    @if ($profile)
        <div class="detail-grid">
            <div><span>Categories</span><strong>{{ implode(', ', $profile->content_categories) }}</strong></div>
            <div><span>Description</span><strong>{{ $profile->content_description }}</strong></div>
            <div><span>Traffic</span><strong>{{ json_encode($profile->traffic_profile) }}</strong></div>
            <div><span>Audience</span><strong>{{ implode(', ', $profile->audience_countries) }}</strong></div>
        </div>

        @if ($canRunThoth && $reviewableForThoth)
            <form method="POST" action="{{ route('admin.publisher-applications.thoth-review', $application) }}" class="inline-form">
                @csrf
                <label><input type="checkbox" name="rerun" value="1"> Deliberate re-run</label>
                <button>Run THOTH Website Review</button>
            </form>
            <p class="muted">This explicit Admin action may refresh stale Task 39 ads.txt verification and collect bounded static public website evidence. It never creates a Site or activates serving.</p>
        @elseif($canRunThoth)
            <p class="muted">THOTH pre-approval review is disabled for this application state. Allowed states are Submitted, Under Review and More Info Required.</p>
        @endif
    @else
        <p class="muted">The applicant has not saved quality-profile evidence yet.</p>
    @endif
</article>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Public website evidence</p><h2>Bounded live evidence snapshot</h2></div><span class="status">{{ count($websiteEvidence) }} page(s)</span></div>
    @if($applicationRun)
        <div class="detail-grid">
            <div><span>Review context</span><strong>{{ $applicationEvidence['review_context'] ?? '—' }}</strong></div>
            <div><span>Authorization verified</span><strong>{{ ($applicationEvidence['application']['website_authorization_verified'] ?? false) ? 'Yes' : 'No' }}</strong></div>
            <div><span>Verification freshness</span><strong>{{ $applicationEvidence['application']['verification_freshness'] ?? '—' }}</strong></div>
            <div><span>Last evidence collection</span><strong>{{ $applicationRun->started_at }}</strong></div>
        </div>
        @forelse($websiteEvidence as $page)
            <div class="compact-row"><div><strong>{{ $page['title'] ?: 'Untitled page' }}</strong><p>{{ $page['url'] }}</p></div><span class="muted">Static HTML text</span></div>
        @empty
            <p class="muted">No acceptable live website page was available. Applicant declarations remain visible for manual review; this is not an automatic rejection.</p>
        @endforelse
        @if($evidenceGaps)
            <h3>Evidence gaps</h3>
            <ul>@foreach($evidenceGaps as $gap)<li>{{ $gap }}</li>@endforeach</ul>
        @endif
    @else
        <p class="muted">No application-specific THOTH website evidence has been collected yet.</p>
    @endif
</article>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">THOTH AI advisory</p><h2>Advisory result</h2></div>@if($applicationRun)<x-status-badge :status="$applicationRun->status" />@endif</div>
    @if($applicationRun)
        <div class="detail-grid">
            <div><span>Recommendation</span><strong>{{ $advisory['recommended_decision'] ?? $applicationRun->error_code ?? 'Unavailable' }}</strong></div>
            <div><span>Risk</span><strong>{{ $advisory['risk_level'] ?? '—' }}</strong></div>
            <div><span>Confidence</span><strong>{{ isset($advisory['confidence']) ? $advisory['confidence'].'%' : '—' }}</strong></div>
            <div><span>Provider / model</span><strong>{{ $applicationRun->provider }} · {{ $applicationRun->model }}</strong></div>
            <div><span>Run timestamp</span><strong>{{ $applicationRun->started_at }}</strong></div>
        </div>
        @if($advisory['findings'] ?? [])
            <h3>Findings</h3>
            @foreach($advisory['findings'] as $finding)
                <div class="compact-row"><div><strong>{{ $finding['code'] ?? 'Finding' }} · {{ $finding['severity'] ?? '—' }}</strong><p>{{ $finding['explanation'] ?? '' }}</p></div></div>
            @endforeach
        @endif
        @if($advisory['positive_signals'] ?? [])<h3>Positive signals</h3><ul>@foreach($advisory['positive_signals'] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif
        @if($advisory['concerns'] ?? [])<h3>Concerns</h3><ul>@foreach($advisory['concerns'] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif
        @if($advisory['recommended_admin_checks'] ?? [])<h3>Recommended checks</h3><ul>@foreach($advisory['recommended_admin_checks'] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif
        <p class="muted"><strong>Human final decision remains separate.</strong> A THOTH recommendation never changes application, Publisher, Organization, Site, serving, financial, contract, HMP or HMS state.</p>
    @else
        <p class="muted">No application-specific THOTH advisory run has been recorded.</p>
    @endif
</article>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Application acceptance evidence</p><h2>Legal documents &amp; consent</h2></div><span class="status">{{ $application->legalAcceptances->count() }} acceptance record(s)</span></div>

    @forelse ($application->legalAcceptances as $acceptance)
        <div class="compact-row"><div><strong>{{ str($acceptance->document_type)->replace('_', ' ')->headline() }} · {{ $acceptance->document_version }}</strong><p><a class="text-link" href="{{ $acceptance->canonical_url }}" target="_blank" rel="noopener noreferrer">Canonical document</a> · Evidence {{ $acceptance->evidence_hash }}</p></div><span class="muted">Accepted {{ $acceptance->accepted_at }}</span></div>
    @empty
        <p class="muted">No legal acceptance evidence has been recorded.</p>
    @endforelse

    <div class="compact-row"><div><strong>Optional marketing consent</strong><p>Recorded independently from contractual/privacy acceptance and never gates transactional application communications.</p></div><span class="muted">{{ $latestMarketingConsent ? (($latestMarketingConsent->opted_in ? 'Opted in' : 'Not opted in').' · '.$latestMarketingConsent->recorded_at) : 'No record' }}</span></div>
</article>

<article class="workspace-section">
    <h2>Immutable submitted revisions</h2>
    @forelse ($application->revisions as $revision)
        <details><summary>Revision {{ $revision->version }} · {{ $revision->submitted_at }}</summary><p>SHA-256 {{ $revision->snapshot_hash }}</p><pre>{{ json_encode($revision->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
    @empty
        <p class="muted">No submitted revision.</p>
    @endforelse
</article>

<article class="workspace-section">
    <h2>Lifecycle history</h2>
    @foreach ($application->events as $event)
        <div class="compact-row"><div><strong>{{ str($event->action)->replace('_', ' ')->headline() }}</strong><p>{{ $event->previous_status ?: 'Created' }} → {{ $event->new_status }} @if ($event->reason)· {{ $event->reason }}@endif</p></div><span class="muted">{{ $event->actor?->name ?: 'System' }} · {{ $event->created_at }}</span></div>
    @endforeach
</article>
@endsection
