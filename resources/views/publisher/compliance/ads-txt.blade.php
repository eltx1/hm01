@extends('layouts.admin')
@section('title', 'Supply Chain Compliance')
@section('heading', 'Supply Chain Compliance')
@section('content')
<section class="hero"><div><p class="eyebrow">Publisher compliance · Ads.txt &amp; Compliance</p><h2>One identity across every artifact</h2><p>Your reviewed seller identity connects the exact <code>/ads.txt</code> file to Horus sellers.json and the schain sent by the permanent browser loader. Private demand credentials and other publishers’ identities are never exposed here.</p></div></section>

<article class="workspace-section"><p class="eyebrow">Your public seller identity</p><h2>sellers.json &amp; schain health</h2>
@forelse($sellerIdentities as $identity)
    <div class="publisher-compliance-card managed-record">
        <div class="workspace-heading"><div><strong><code>{{ $identity['seller_id'] }}</code></strong><p>{{ $identity['seller_type'] }} · {{ $identity['is_confidential'] ? 'Confidential public declaration' : ($identity['public_name'].' · '.$identity['public_domain']) }}</p></div><div class="status-row"><x-status-badge :status="$identity['status']" /><x-status-badge :status="$identity['review_status']" /></div></div>
        <div class="status-row"><span>sellers.json</span><x-status-badge :status="$identity['sellers_json_health']" /><span>Ads.txt</span><x-status-badge :status="$identity['ads_txt_health']" /><span>schain</span><x-status-badge :status="$identity['schain_health']" /></div>
        @foreach($identity['sites'] as $identitySite)<div class="compact-row"><span>{{ $identitySite['domain'] }}</span><div class="status-row"><x-status-badge :status="$identitySite['ads_txt_health']" /><x-status-badge :status="$identitySite['schain_health']" /></div></div>@endforeach
        @if($identity['review_status'] !== \App\Enums\SupplyChainReviewStatus::Verified)<p class="muted">Structural identity changes require Horus Admin review. Contact your account team; publisher users cannot change seller IDs.</p>@endif
    </div>
@empty<p class="muted">Horus has not configured a seller identity for this publisher. Ads.txt demand records remain visible below; contact your account team before programmatic activation that requires Horus sellers.json and schain.</p>@endforelse
</article>

@forelse($sites as $site)
@php($summary = $summaries[$site->id])
@php($deployment = $deploymentStates[$site->id])
<article class="workspace-section publisher-compliance-card">
    <div class="workspace-heading"><div><p class="eyebrow">{{ $site->display_name }}</p><h2>{{ $site->primary_domain }}/ads.txt</h2><div class="status-row"><x-status-badge :status="$summary['status']" /><span class="muted">Last check: {{ $summary['last_checked']?->diffForHumans() ?: 'never' }}</span></div></div><div class="status-row"><button class="hm-button-primary" type="button" data-copy-target="publisher-ads-txt-{{ $site->id }}">Copy All</button><a class="hm-button-secondary" href="{{ route('publisher.ads-txt.download', $site) }}">Download</a>@if(auth()->user()->hasPermission('publisher.ads_txt.verify_own'))<form method="POST" action="{{ route('publisher.ads-txt.verify', $site) }}">@csrf<button class="hm-button-secondary">Check Again</button></form>@endif</div></div>
    <p>{{ $deployment['next_action'] }}</p>
    <div class="managed-record">
        <div class="workspace-heading"><div><p class="eyebrow">Deployment mode</p><h3>{{ $deployment['deployment_mode'] }}</h3></div><x-status-badge :status="$deployment['deployment_mode'] === 'MANAGED_REDIRECT_DELEGATION' ? $deployment['redirect_status'] : 'MANUAL_COPY'" /></div>
        <p>{{ $deployment['instructions'] }}</p>
        @if($deployment['deployment_mode'] === 'MANAGED_REDIRECT_DELEGATION')
            <p>Managed canonical target: <code>{{ $deployment['managed_target'] }}</code></p>
            <small class="muted">Redirect verification: {{ $deployment['redirect_status'] }}{{ $deployment['redirect_verified_at'] ? ' · '.$deployment['redirect_verified_at']->diffForHumans() : '' }}</small>
        @endif
    </div>
    <pre id="publisher-ads-txt-{{ $site->id }}" class="compliance-code">{{ $deployment['canonical'] }}</pre>
    <div class="compliance-diff-grid">
        <div><h3>Correct records</h3>@forelse($summary['comparison']['correct'] ?? [] as $item)<code class="record-line record-correct">{{ $item['canonical'] }}</code>@empty<p class="muted">Nothing confirmed yet.</p>@endforelse</div>
        <div><h3>Missing required records</h3>@forelse($summary['comparison']['missing'] ?? [] as $item)<code class="record-line record-missing">{{ $item['canonical'] }}</code>@empty<p class="muted">No missing seller records.</p>@endforelse @foreach($summary['comparison']['missing_directives'] ?? [] as $item)<code class="record-line record-missing">{{ $item['canonical'] }}</code>@endforeach</div>
        <div><h3>Invalid or conflicting</h3>@forelse(array_merge($summary['comparison']['invalid'] ?? [], $summary['comparison']['conflicts'] ?? []) as $item)<div class="finding finding-danger"><span>{{ $item['message'] }}</span>@if(isset($item['content']))<code>{{ $item['content'] }}</code>@endif</div>@empty<p class="muted">No invalid or conflicting declarations.</p>@endforelse</div>
        <div><h3>Additional live records</h3>@forelse($summary['comparison']['additional'] ?? [] as $item)<code class="record-line">{{ $item['canonical'] }}</code>@empty<p class="muted">No additional live records.</p>@endforelse</div>
    </div>
</article>
@empty<article><p class="muted">No publisher websites are configured yet.</p></article>@endforelse
@endsection
