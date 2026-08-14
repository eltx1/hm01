@extends('layouts.admin')
@section('title', 'Publisher Application')
@section('heading', 'Publisher Application Review')
@section('content')
@php($profile = $application->publisher->qualityProfiles->first())
<section class="hero"><div><p class="eyebrow">{{ $application->applicant->name }} · {{ $application->applicant->email }}</p><h2>{{ $application->publisher->display_name }}</h2><p>{{ $application->publisher->legal_name }} · {{ $application->primary_domain }}</p></div><div class="status-row"><x-status-badge :status="$application->status" /><span class="status">Revision {{ $application->current_revision ?: 'not submitted' }}</span></div></section>
<div class="notice"><strong>Decision boundary:</strong> application approval creates operational Publisher eligibility only. Website approval and production monetization remain separate.</div>

<section class="metric-grid">
    <article><p class="eyebrow">Created</p><strong>{{ $application->created_at }}</strong></article>
    <article><p class="eyebrow">First submitted</p><strong>{{ $application->submitted_at ?: 'Not submitted' }}</strong></article>
    <article><p class="eyebrow">Last submitted</p><strong>{{ $application->last_submitted_at ?: 'Not submitted' }}</strong></article>
    <article><p class="eyebrow">Email</p><strong>{{ $application->applicant->hasVerifiedEmail() ? 'Verified' : 'Not verified' }}</strong></article>
    <article><p class="eyebrow">Publisher account</p><strong>{{ $application->publisher->status->value }}</strong></article>
</section>

@if(auth()->user()->hasPermission('publisher_applications.review'))
<article class="workspace-section"><h2>Review actions</h2><div class="status-row">
    @if($application->status === \App\Enums\PublisherApplicationStatus::Submitted)<form method="POST" action="{{ route('admin.publisher-applications.start-review', $application) }}">@csrf<button class="hm-button-primary">Start review</button></form>@endif
    @if($application->status === \App\Enums\PublisherApplicationStatus::UnderReview)
        <details><summary>Request more information</summary><form method="POST" action="{{ route('admin.publisher-applications.request-information', $application) }}" class="form-stack">@csrf<label>Required applicant-visible request<textarea name="reason" required maxlength="5000"></textarea></label><button>Send information request</button></form></details>
        <form method="POST" action="{{ route('admin.publisher-applications.approve', $application) }}">@csrf<button class="hm-button-primary">Approve application</button></form>
        <details><summary>Reject</summary><form method="POST" action="{{ route('admin.publisher-applications.reject', $application) }}" class="form-stack">@csrf<label>Required decision reason<textarea name="reason" required maxlength="5000"></textarea></label><button class="danger-button">Reject application</button></form></details>
    @endif
    @if($application->status === \App\Enums\PublisherApplicationStatus::Approved)<a class="hm-button-primary button-link" href="{{ route('admin.publishers.show', $application->publisher) }}">Open Publisher 360</a>@endif
</div></article>
@endif

<article class="workspace-section"><div class="workspace-heading"><div><p class="eyebrow">Canonical Task 24 profile</p><h2>Publisher quality evidence</h2></div>@if($profile)<span class="status">Version {{ $profile->version }}</span>@endif</div>
@if($profile)<div class="detail-grid"><div><span>Categories</span><strong>{{ implode(', ', $profile->content_categories) }}</strong></div><div><span>Description</span><strong>{{ $profile->content_description }}</strong></div><div><span>Traffic</span><strong>{{ json_encode($profile->traffic_profile) }}</strong></div><div><span>Audience</span><strong>{{ implode(', ', $profile->audience_countries) }}</strong></div></div>
@if(auth()->user()->hasPermission('publisher_quality.ai.run'))<form method="POST" action="{{ route('admin.publishers.quality-review.run', $application->publisher) }}" class="inline-form">@csrf<label><input type="checkbox" name="rerun" value="1"> Deliberate re-run</label><button>Run THOTH advisory</button></form><p class="muted">THOTH may append advisory evidence but can never approve or reject this application.</p>@endif
@else<p class="muted">The applicant has not saved quality-profile evidence yet.</p>@endif
@forelse($application->publisher->qualityReviewRuns as $run)<div class="compact-row"><div><strong>{{ $run->provider }} · {{ $run->model }}</strong><p>{{ $run->result['recommended_decision'] ?? $run->error_code ?? 'Pending' }} · advisory only</p></div><x-status-badge :status="$run->status" /></div>@empty@endforelse
</article>

<article class="workspace-section"><h2>Immutable submitted revisions</h2>@forelse($application->revisions as $revision)<details><summary>Revision {{ $revision->version }} · {{ $revision->submitted_at }}</summary><p>SHA-256 {{ $revision->snapshot_hash }}</p><pre>{{ json_encode($revision->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@empty<p class="muted">No submitted revision.</p>@endforelse</article>
<article class="workspace-section"><h2>Lifecycle history</h2>@foreach($application->events as $event)<div class="compact-row"><div><strong>{{ str($event->action)->replace('_', ' ')->headline() }}</strong><p>{{ $event->previous_status ?: 'Created' }} → {{ $event->new_status }}@if($event->reason) · {{ $event->reason }}@endif</p></div><span class="muted">{{ $event->actor?->name ?: 'System' }} · {{ $event->created_at }}</span></div>@endforeach</article>
@endsection
