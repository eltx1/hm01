@extends('layouts.admin')
@section('title', $publisher->display_name.' · Publisher 360')
@section('heading', 'Publisher 360')
@section('content')
@php
    $tabs = [
        ['label' => 'Overview', 'href' => '#overview'],
        ['label' => 'Websites', 'href' => '#websites'],
        ['label' => 'Commercial terms', 'href' => '#contracts', 'visible' => auth()->user()->hasPermission('contracts.view')],
        ['label' => 'Monetization', 'href' => '#monetization'],
        ['label' => 'Compliance', 'href' => '#compliance'],
        ['label' => 'Quality Review', 'href' => '#quality-review', 'visible' => auth()->user()->hasPermission('publisher_quality.review')],
        ['label' => 'Reporting', 'href' => '#reporting', 'visible' => $reporting !== null],
        ['label' => 'Finance', 'href' => '#finance', 'visible' => $reporting !== null],
        ['label' => 'Users', 'href' => '#users', 'visible' => auth()->user()->hasPermission('users.view')],
        ['label' => 'Audit', 'href' => '#audit', 'visible' => auth()->user()->hasPermission('audit.view')],
    ];
    $activeSites = $publisher->sites->where('status', \App\Enums\SiteStatus::Active)->count();
    $verifiedDomains = $publisher->sites->flatMap->domains->where('verification_status', 'VERIFIED')->count();
@endphp

<x-control-plane.workspace-tabs :items="$tabs" label="Publisher 360 sections" />

<section id="overview" class="hero workspace-section">
    <div>
        <p class="eyebrow">Publisher account</p><h2>{{ $publisher->display_name }}</h2>
        <p>{{ $publisher->legal_name }} · {{ $publisher->organization->name }}</p>
        <div class="status-row"><x-status-badge :status="$publisher->status" /><span class="status">Websites reviewed separately</span></div>
        @if(auth()->user()->hasPermission('publishers.manage'))<a class="hm-button-primary button-link" href="{{ route('admin.publishers.edit', $publisher) }}">Edit publisher</a>@endif
    </div>
</section>

<section class="metric-grid" aria-label="Publisher 360 summary">
    <article><p class="eyebrow">Websites</p><strong class="metric">{{ $publisher->sites->count() }}</strong><span class="muted">{{ $activeSites }} active</span></article>
    <article><p class="eyebrow">Verified domains</p><strong class="metric">{{ $verifiedDomains }}</strong><span class="muted">{{ $publisher->sites->flatMap->domains->count() }} authorized</span></article>
    <article><p class="eyebrow">Users</p><strong class="metric">{{ $publisher->organization->users->count() }}</strong><span class="muted">Organization members</span></article>
    <article><p class="eyebrow">Commercial terms</p><strong class="metric">{{ $publisher->contracts->count() }}</strong><span class="muted">{{ $publisher->contracts->where('status', \App\Enums\ContractStatus::Active)->count() }} active</span></article>
    @if($reporting)<article><p class="eyebrow">Balance due</p><strong class="metric">{{ \App\Support\Money::formatMinor((int) $reporting['payment_balance_minor']) }} {{ $reporting['currency'] }}</strong><span class="muted">Latest finalized {{ $reporting['currency'] }} statement</span></article>@endif
</section>

<article id="websites" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Inventory ownership</p><h2>Websites</h2></div><div>@if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary button-link" href="{{ route('admin.publishers.sites.create', $publisher) }}">Add website</a>@endif <a class="section-anchor" href="{{ route('admin.sites.index') }}">All websites</a></div></div>
    <div class="compact-list">
        @forelse($publisher->sites as $site)
            <a class="compact-row" href="{{ route('admin.sites.show', $site) }}"><div><strong>{{ $site->display_name }}</strong><p>{{ $site->primary_domain }} · {{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</p></div><x-status-badge :status="$site->status" /></a>
        @empty<p class="muted">No websites are attached to this publisher.</p>@endforelse
    </div>
</article>

@if(auth()->user()->hasPermission('publisher_quality.review'))
@php($qualityProfile = $publisher->qualityProfiles->first())
<article id="quality-review" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">THOTH · Human authority</p><h2>Publisher Quality Review</h2></div><x-status-badge :status="$thothSettings->enabled ? 'AI ENABLED' : 'AI DISABLED'" /></div>
    <p class="muted">AI output is advisory evidence only. It never changes Publisher state, serving, or finance. Your signed human decision is authoritative.</p>
    <details><summary>{{ $qualityProfile ? 'Create a new profile version' : 'Complete quality profile' }}</summary>
    <form method="POST" action="{{ route('admin.publishers.quality-profile', $publisher) }}" class="form-grid safe-submit">@csrf
        <label>Content categories (one or more)<select name="content_categories[]" multiple required>@foreach(['NEWS','ENTERTAINMENT','SPORTS','TECHNOLOGY','LIFESTYLE','BUSINESS','OTHER'] as $category)<option @selected(in_array($category, old('content_categories', $qualityProfile?->content_categories ?? [])))>{{ $category }}</option>@endforeach</select></label>
        <label>Content description<textarea name="content_description" required>{{ old('content_description', $qualityProfile?->content_description) }}</textarea></label>
        <label>Monthly pageviews<input type="number" name="monthly_pageviews" min="0" value="{{ old('monthly_pageviews', data_get($qualityProfile?->traffic_profile, 'monthly_pageviews')) }}"></label>
        @foreach(['organic','social','direct','paid','other'] as $source)<label>{{ str($source)->headline() }} traffic %<input type="number" name="{{ $source }}_percent" min="0" max="100" value="{{ old($source.'_percent', data_get($qualityProfile?->traffic_profile, $source, $source === 'direct' ? 100 : 0)) }}" required></label>@endforeach
        <label>Audience countries (ISO-2)<select name="audience_countries[]" multiple required>@foreach(['US','GB','CA','AU','AE','SA','EG','FR','DE','IN','OTHER'] as $country)<option @selected(in_array($country, old('audience_countries', $qualityProfile?->audience_countries ?? [])))>{{ $country }}</option>@endforeach</select></label>
        @foreach(['desktop','mobile','tablet'] as $device)<label>{{ str($device)->headline() }} %<input type="number" name="{{ $device }}_percent" min="0" max="100" value="{{ old($device.'_percent', data_get($qualityProfile?->device_mix, $device, $device === 'desktop' ? 100 : 0)) }}" required></label>@endforeach
        @foreach(['original_content','user_generated_content','ai_assisted_content','sensitive_content','has_privacy_policy','has_contact_details','has_cmp','prior_policy_incidents'] as $flag)<label><input type="checkbox" name="{{ $flag }}" value="1" @checked(old($flag, data_get($qualityProfile?->declarations, $flag, false)))> {{ str($flag)->replace('_', ' ')->headline() }}</label>@endforeach
        <label>Monetization history<textarea name="monetization_history">{{ old('monetization_history', data_get($qualityProfile?->traffic_profile, 'monetization_history')) }}</textarea></label>
        <label>Reviewer comments<textarea name="review_comments">{{ old('review_comments', $qualityProfile?->review_comments) }}</textarea></label><button>Save immutable profile version</button>
    </form></details>
    @if($qualityProfile)<p><strong>Current profile:</strong> version {{ $qualityProfile->version }} · {{ $qualityProfile->created_at }}</p>@endif
    @if(auth()->user()->hasPermission('publisher_quality.ai.run'))<form method="POST" action="{{ route('admin.publishers.quality-review.run', $publisher) }}" class="inline safe-submit">@csrf<label><input type="checkbox" name="rerun" value="1"> Deliberate re-run</label><button @disabled(!$thothSettings->enabled || !$qualityProfile)>Run THOTH advisory</button></form>@endif
    <h3>Advisory history</h3>
    @forelse($publisher->qualityReviewRuns as $run)<div class="compact-row"><div><strong>AI ADVISORY RECOMMENDATION · {{ $run->provider }} · {{ $run->model }}</strong><p>{{ $run->result['recommended_decision'] ?? $run->error_code ?? 'Pending' }} · Risk {{ $run->result['risk_level'] ?? 'unknown' }} · confidence {{ isset($run->result['confidence']) ? $run->result['confidence'].'%' : 'n/a' }} · profile v{{ $run->profile?->version ?? '?' }} · {{ count($run->evidence_snapshot['website_evidence'] ?? []) }} public pages</p><p>{{ $run->result['summary'] ?? 'No advisory result.' }}</p>@foreach(($run->result['findings'] ?? []) as $finding)<p><strong>{{ $finding['severity'] }} · {{ $finding['code'] }}</strong> {{ $finding['explanation'] }} — Evidence: {{ $finding['evidence'] }}</p>@endforeach @if($run->result['positive_signals'] ?? [])<p><strong>Positive signals:</strong> {{ implode(' · ', $run->result['positive_signals']) }}</p>@endif @if($run->result['concerns'] ?? [])<p><strong>Concerns:</strong> {{ implode(' · ', $run->result['concerns']) }}</p>@endif @if($run->result['recommended_admin_checks'] ?? [])<p><strong>Recommended checks:</strong> {{ implode(' · ', $run->result['recommended_admin_checks']) }}</p>@endif @if($run->result['limitations'] ?? [])<p class="muted">Limitations: {{ implode(' · ', $run->result['limitations']) }}</p>@endif</div><x-status-badge :status="$run->status" /></div>@empty<p class="muted">No THOTH advisories have been requested.</p>@endforelse
    <h3>Human final decision</h3>
    <form method="POST" action="{{ route('admin.publishers.review', $publisher) }}" class="form-grid safe-submit">@csrf<label>Decision<select name="decision" required><option>APPROVE</option><option>NEEDS_INFORMATION</option><option>REJECT</option></select></label><label>Advisory reference<select name="review_run_id"><option value="">No AI reference</option>@foreach($publisher->qualityReviewRuns->where('status','COMPLETED') as $run)<option value="{{ $run->id }}">{{ $run->created_at }} · {{ $run->result['recommended_decision'] ?? '' }}</option>@endforeach</select></label><label>Required human reason<textarea name="reason" required maxlength="5000"></textarea></label><button>Record human decision</button></form>
    @forelse($publisher->qualityDecisions as $decision)<div class="compact-row"><div><strong>{{ $decision->decision }}</strong><p>{{ $decision->reason }}</p></div><span class="muted">{{ $decision->created_at }}</span></div>@empty<p class="muted">No human quality decision recorded.</p>@endforelse
</article>
@endif

@if(auth()->user()->hasPermission('contracts.view'))
<article id="contracts" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Commercial terms</p><h2>Revenue and payment defaults</h2></div><a class="section-anchor" href="{{ route('admin.publishers.contracts.index', $publisher) }}">Manage terms</a></div>
    @forelse($publisher->contracts as $contract)<div class="compact-row"><div><strong>{{ $contract->contract_reference }}</strong><p>{{ $contract->starts_at?->format('M j, Y') }} – {{ $contract->ends_at?->format('M j, Y') ?: 'Open ended' }} · Default {{ $contract->revenue_share_percent }}%</p></div><x-status-badge :status="$contract->status" /></div>@empty<p class="muted">No commercial terms.</p>@endforelse
</article>
@endif

<article id="monetization" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Serving architecture</p><h2>Monetization</h2></div></div>
    <div class="health-grid">
        <div><span class="muted">HORUS_GAM sites</span><strong class="metric-small">{{ $publisher->sites->where('serving_mode', \App\Enums\ServingMode::HorusGam)->count() }}</strong></div>
        <div><span class="muted">Prebid enabled</span><strong class="metric-small">{{ $publisher->sites->where('prebid_enabled', true)->count() }}</strong></div>
        <div><span class="muted">Native demand enabled</span><strong class="metric-small">{{ $publisher->sites->where('native_demand_enabled', true)->count() }}</strong></div>
    </div>
</article>

<article id="compliance" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Identity and domain evidence</p><h2>Compliance</h2></div></div>
    <p class="muted">The publisher business identity is canonical for OWNERDOMAIN. Tasks 3–4 add live Ads.txt checks and the complete seller control center.</p>
    <div class="compact-row"><div><strong>{{ $publisher->business_domain ?: 'Business domain not declared' }}</strong><p>{{ $publisher->business_domain ? 'Publisher business domain · canonical OWNERDOMAIN' : 'Legacy per-site OWNERDOMAIN fallback · Admin review required' }}</p></div><x-status-badge :status="$publisher->supply_chain_review_status" /></div>
    @foreach($publisher->sites as $site)@foreach($site->domains as $domain)<div class="compact-row"><div><strong>{{ $domain->domain }}</strong><p>{{ $site->display_name }}</p></div><x-status-badge :status="$domain->verification_status" /></div>@endforeach @endforeach
</article>

@if($reporting)
<article id="reporting" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Aggregated reporting</p><h2>Publisher performance</h2></div><a class="section-anchor" href="{{ route('admin.reporting.index') }}">Reporting control</a></div>
    <div class="health-grid"><div><span class="muted">Impressions</span><strong class="metric-small">{{ number_format($reporting['impressions']) }}</strong></div><div><span class="muted">Publisher earnings</span><strong class="metric-small">{{ \App\Support\Money::formatMinor((int) $reporting['revenue_minor']) }} {{ $reporting['currency'] }}</strong></div><div><span class="muted">Balance due</span><strong class="metric-small">{{ \App\Support\Money::formatMinor((int) $reporting['payment_balance_minor']) }} {{ $reporting['currency'] }}</strong></div></div>
</article>
<article id="finance" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Payout readiness</p><h2>Finance</h2></div>@if(auth()->user()->hasPermission('publisher_payments.manage'))<a class="section-anchor" href="{{ route('admin.publishers.payment-profile.edit', $publisher) }}">Payment profile</a>@endif</div>
    <div class="compact-row"><div><strong>Payment profile</strong><p>{{ $publisher->paymentProfile?->payment_method ?: 'Not configured' }} · {{ $publisher->paymentProfile?->currency ?: 'No currency' }}</p></div><x-status-badge :status="$publisher->paymentProfile?->verification_status?->value ?? 'INCOMPLETE'" /></div>
    @forelse($statements as $statement)<a class="compact-row" href="{{ route('admin.reporting.statements.show', $statement) }}"><div><strong>{{ $statement->statement_number }}</strong><p>{{ $statement->period->period_key }} · {{ \App\Support\Money::formatMinor((int) $statement->balance_due_minor) }} {{ $statement->currency }} due</p></div><x-status-badge :status="$statement->status" /></a>@empty<p class="muted">No finalized statements.</p>@endforelse
</article>
@endif

@if(auth()->user()->hasPermission('users.view'))
<article id="users" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Organization access</p><h2>Users</h2></div>@if(auth()->user()->hasPermission('users.invite'))<a class="section-anchor" href="{{ route('admin.invitations.create') }}">Invite user</a>@endif</div>
    @forelse($publisher->organization->users as $member)<div class="compact-row"><div><strong>{{ $member->name }}</strong><p>{{ $member->email }} · {{ $member->roles->pluck('display_name')->join(', ') }}</p></div><x-status-badge :status="$member->status" /></div>@empty<p class="muted">No organization users.</p>@endforelse
</article>
@endif

@if(auth()->user()->hasPermission('audit.view'))
<article id="audit" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Immutable evidence</p><h2>Recent audit activity</h2></div></div>
    @forelse($auditEvents as $event)<div class="compact-row"><div><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong><p>{{ $event->auditable_type ? class_basename($event->auditable_type) : 'Organization' }}</p></div><span class="muted">{{ $event->created_at }}</span></div>@empty<p class="muted">No audit activity for this organization.</p>@endforelse
</article>
@endif
@endsection
