@extends('layouts.admin')
@section('title', 'Supply Chain Control Center')
@section('heading', 'Supply Chain Control Center')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Compliance / Supply Chain</p>
        <h2>Ads.txt, sellers.json and schain in one operational workspace</h2>
        <p>Every status below is computed from the same canonical supply-chain services used to build static edge artifacts. Operators do not need database access or manual file inspection.</p>
    </div>
    @if(($data['last_publication'] ?? null))
        <div class="hero-stat"><strong>{{ $data['last_publication']['delivered_at']?->diffForHumans() ?: 'Unknown' }}</strong><span>last supply-chain publication</span></div>
    @endif
</section>

<x-control-plane.workspace-tabs :items="collect($tabs)->map(fn ($tab) => ['label' => $tab['label'], 'href' => $tab['href']])->all()" label="Supply chain control center sections" />

@if($section === 'overview')
<div class="metric-grid">
    <article><span>Websites</span><strong>{{ $data['site_count'] }}</strong><small>{{ $data['compliant_site_count'] }} compliant</small></article>
    <article><span>Active sellers</span><strong>{{ $data['active_seller_count'] }}</strong><small>current sellers.json entities</small></article>
    <article><span>Master records</span><strong>{{ $data['active_master_count'] }}</strong><small>active platform authorizations</small></article>
    <article><span>Bidder records</span><strong>{{ $data['active_bidder_record_count'] }}</strong><small>active records</small></article>
    <article><span>Direct demand records</span><strong>{{ $data['active_demand_record_count'] }}</strong><small>active records</small></article>
    <article><span>Findings</span><strong>{{ $data['error_count'] }}</strong><small>{{ $data['warning_count'] }} warning(s)</small></article>
</div>
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Public origin</p><h2>Horus sellers.json</h2></div><x-status-badge :status="$data['public_origin']['verified'] ? 'VERIFIED' : 'BLOCKED'" /></div>
    <p><code>{{ config('supply-chain.canonical_sellers_json_url') }}</code></p>
    <p class="muted">Current checksum: <code>{{ $data['sellers_checksum'] }}</code> · {{ $data['public_origin']['checked_at'] ? 'Last verified '.$data['public_origin']['checked_at'] : 'No current verification evidence' }}</p>
</article>
<article class="workspace-section">
    <p class="eyebrow">Network status</p><h2>Websites needing attention</h2>
    <div class="table-wrap"><table><thead><tr><th>Publisher / website</th><th>Ads.txt</th><th>sellers.json</th><th>SChain</th><th>Seller ID</th><th>Next action</th></tr></thead><tbody>
    @forelse($data['site_rows'] as $row)
        <tr><td><strong>{{ $row['site']->publisher?->display_name }}</strong><br><a class="section-anchor" href="{{ route('admin.compliance.supply-chain.site', $row['site']) }}">{{ $row['site']->primary_domain }}</a></td><td><x-status-badge :status="$row['ads_txt']" /></td><td><x-status-badge :status="$row['sellers_json']" /></td><td><x-status-badge :status="$row['schain']" /></td><td><code>{{ $row['seller_id'] ?: '—' }}</code></td><td>{{ $row['action'] }}</td></tr>
    @empty<tr><td colspan="6">No websites are configured.</td></tr>@endforelse
    </tbody></table></div>
</article>
@endif

@if($section === 'master-ads-txt')
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Platform-wide authorization</p><h2>Master Ads.txt</h2><p class="muted">A reviewed active master record is eligible for every approved or active Horus-managed website.</p></div><a class="hm-button-primary" href="{{ route('admin.compliance.ads-txt.master.index') }}">Manage records</a></div>
    <div class="notice"><strong>Impact preview:</strong> This record will appear on {{ $data['impact_count'] }} eligible websites when enabled, subject to canonical conflict rules.</div>
    <div class="table-wrap"><table><thead><tr><th>Authorization</th><th>Status</th><th>Review</th><th>Remote sellers.json</th><th>Effective window</th></tr></thead><tbody>
    @forelse($data['records'] as $record)
        <tr><td><code>{{ $record->raw_record }}</code></td><td><x-status-badge :status="$record->status" /></td><td><x-status-badge :status="$record->review_status?->value ?? (string) $record->review_status" /></td><td><x-status-badge :status="$record->remote_verification_status" /></td><td>{{ $record->effective_from?->toDateTimeString() ?: 'Immediate' }} → {{ $record->effective_to?->toDateTimeString() ?: 'No expiry' }}</td></tr>
    @empty<tr><td colspan="5">No master authorizations exist.</td></tr>@endforelse
    </tbody></table></div>
</article>
@endif

@if($section === 'horus-sellers')
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Canonical Horus seller identities</p><h2>Horus Sellers</h2></div><a class="hm-button-primary" href="{{ route('admin.compliance.sellers.index') }}">Manage seller identities</a></div>
    <div class="table-wrap"><table><thead><tr><th>Seller ID</th><th>Publisher</th><th>Type</th><th>Public identity</th><th>Confidential</th><th>Status</th><th>Review</th><th>Scope</th></tr></thead><tbody>
    @forelse($data['declarations'] as $seller)
        <tr><td><a class="section-anchor" href="{{ route('admin.compliance.sellers.show', $seller) }}"><code>{{ $seller->seller_id }}</code></a></td><td>{{ $seller->publisher?->display_name }}</td><td>{{ $seller->seller_type?->value ?? (string) $seller->seller_type }}</td><td>{{ $seller->is_confidential ? 'Hidden publicly' : ($seller->name.' · '.$seller->domain) }}</td><td>{{ $seller->is_confidential ? 'Yes' : 'No' }}</td><td><x-status-badge :status="$seller->status?->value ?? (string) $seller->status" /></td><td><x-status-badge :status="$seller->review_status?->value ?? (string) $seller->review_status" /></td><td>{{ $seller->site?->primary_domain ?: 'Publisher-wide' }}</td></tr>
    @empty<tr><td colspan="8">No seller identities are configured.</td></tr>@endforelse
    </tbody></table></div>
</article>
@endif

@if($section === 'bidder-authorizations')
<article class="workspace-section">
    <p class="eyebrow">Prebid account-aware supply chain</p><h2>Bidder Authorizations</h2>
    <p class="muted">Readiness is evaluated only for enabled bidder accounts mapped to websites where Header Bidding is actually in use.</p>
    <div class="table-wrap"><table><thead><tr><th>Bidder account</th><th>Ads.txt requirement</th><th>Mapped sites</th><th>Remote sellers.json</th><th>Financial source</th><th>Privacy</th><th>Supply chain</th></tr></thead><tbody>
    @forelse($data['accounts'] as $row)
        <tr><td><a class="section-anchor" href="{{ route('admin.compliance.supply-chain.bidder', $row['account']) }}">{{ $row['account']->name }}</a><small class="table-note">{{ $row['account']->bidder?->name ?? $row['account']->bidder?->code }}</small></td><td><x-status-badge :status="$row['ads_txt_requirement']" /></td><td>{{ $row['mapped_sites']->count() }}</td><td><x-status-badge :status="$row['remote_sellers_json_status']" /></td><td><x-status-badge :status="$row['financial_source_readiness']" /></td><td><x-status-badge :status="$row['privacy_readiness']" /></td><td><x-status-badge :status="$row['supply_chain_readiness']" /></td></tr>
    @empty<tr><td colspan="7">No bidder accounts are configured.</td></tr>@endforelse
    </tbody></table></div>
</article>
@endif

@if($section === 'direct-demand-authorizations')
<article class="workspace-section">
    <p class="eyebrow">Direct demand sources</p><h2>Direct Demand Authorizations</h2>
    <p class="muted">These records are composed only for eligible demand-account/site mappings. Commercial credentials are not shown.</p>
    <div class="table-wrap"><table><thead><tr><th>Authorization</th><th>Demand source</th><th>Scope</th><th>Status</th><th>Review</th></tr></thead><tbody>
    @forelse($data['records'] as $record)
        <tr><td><code>{{ $record->raw_record }}</code></td><td>{{ $record->account?->network?->name }} · {{ $record->account?->name }}</td><td>{{ $record->site?->primary_domain ?: 'Account-wide' }}</td><td><x-status-badge :status="$record->status" /></td><td><x-status-badge :status="$record->review_status?->value ?? (string) $record->review_status" /></td></tr>
    @empty<tr><td colspan="5">No direct-demand ads.txt records are configured.</td></tr>@endforelse
    </tbody></table></div>
</article>
@endif

@if($section === 'websites')
<article class="workspace-section">
    <p class="eyebrow">Per-domain control</p><h2>Websites</h2>
    <div class="table-wrap"><table><thead><tr><th>Publisher / website</th><th>Status</th><th>Seller ID</th><th>Ads.txt</th><th>sellers.json</th><th>SChain</th><th>Last verification</th><th>Next action</th></tr></thead><tbody>
    @forelse($data['sites'] as $row)
        <tr><td><strong>{{ $row['site']->publisher?->display_name }}</strong><br><a class="section-anchor" href="{{ route('admin.compliance.supply-chain.site', $row['site']) }}">{{ $row['site']->primary_domain }}</a></td><td><x-status-badge :status="$row['status']" /></td><td><code>{{ $row['seller_id'] ?: '—' }}</code></td><td><x-status-badge :status="$row['ads_txt']" /></td><td><x-status-badge :status="$row['sellers_json']" /></td><td><x-status-badge :status="$row['schain']" /></td><td>{{ $row['last_checked']?->diffForHumans() ?: 'Never' }}</td><td>{{ $row['action'] }}</td></tr>
    @empty<tr><td colspan="8">No websites are configured.</td></tr>@endforelse
    </tbody></table></div>
</article>
@endif

@if($section === 'sellers-json')
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Global public artifact</p><h2>sellers.json</h2><p>{{ $data['count'] }} active seller entities · checksum <code>{{ $data['checksum'] }}</code></p></div><div class="button-row"><a class="hm-button-secondary" href="{{ route('admin.compliance.sellers.artifact') }}" target="_blank" rel="noopener">Open generated artifact</a>@if(auth()->user()->hasPermission('supply_chain.ads_txt.verify'))<form method="POST" action="{{ route('admin.compliance.supply-chain.sellers-json.verify') }}">@csrf<button class="hm-button-primary">Verify public origin</button></form>@endif</div></div>
    <div class="status-row"><span>Canonical public origin</span><x-status-badge :status="$data['public_origin']['verified'] ? 'VERIFIED' : 'BLOCKED'" /><code>{{ config('supply-chain.canonical_sellers_json_url') }}</code></div>
    @if($data['last_publication'])<p class="muted">Last deployed artifact: {{ $data['last_publication']['delivered_at']?->toDateTimeString() }} · manifest <code>{{ $data['last_publication']['manifest_hash'] }}</code></p>@else<p class="muted">No deployed SUPPLY_CHAIN publication is recorded yet.</p>@endif
    <div class="table-wrap"><table><thead><tr><th>Seller ID</th><th>Type</th><th>Public name</th><th>Domain</th><th>Confidential</th><th>Review</th><th>Sites using seller</th><th>Consistency findings</th></tr></thead><tbody>
    @forelse($data['entities'] as $entity)
        @php($seller = $entity['seller'])
        <tr><td><code>{{ $seller['seller_id'] ?? '—' }}</code></td><td>{{ $seller['seller_type'] ?? '—' }}</td><td>{{ $seller['name'] ?? 'Confidential / omitted' }}</td><td>{{ $seller['domain'] ?? 'Confidential / omitted' }}</td><td>{{ isset($seller['is_confidential']) && $seller['is_confidential'] ? 'Yes' : 'No' }}</td><td><x-status-badge :status="$entity['review_state']" /></td><td>@forelse($entity['sites'] as $site)<a class="section-anchor" href="{{ route('admin.compliance.supply-chain.site', $site) }}">{{ $site->primary_domain }}</a>@if(! $loop->last)<br>@endif @empty—@endforelse</td><td>@forelse($entity['findings'] as $finding)<div class="finding {{ ($finding['severity'] ?? '') === 'ERROR' ? 'finding-danger' : '' }}"><strong>{{ $finding['code'] ?? 'Finding' }}</strong> {{ $finding['message'] ?? '' }}</div>@empty<span class="muted">None</span>@endforelse</td></tr>
    @empty<tr><td colspan="8">No active sellers are emitted.</td></tr>@endforelse
    </tbody></table></div>
</article>
@endif

@if($section === 'findings')
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Verification / Findings</p><h2>Cross-artifact findings</h2></div><div class="status-row"><span>sellers.json origin</span><x-status-badge :status="$data['public_origin']['verified'] ? 'VERIFIED' : 'BLOCKED'" /></div></div>
    <div class="table-wrap"><table><thead><tr><th>Severity</th><th>Code</th><th>Website / Seller</th><th>Finding</th></tr></thead><tbody>
    @forelse($data['findings'] as $finding)
        <tr><td><x-status-badge :status="$finding['severity'] ?? 'INFO'" /></td><td><code>{{ $finding['code'] ?? 'UNKNOWN' }}</code></td><td>{{ $finding['site_domain'] ?? $finding['site_id'] ?? $finding['seller_id'] ?? 'Network' }}</td><td>{{ $finding['message'] ?? '' }}</td></tr>
    @empty<tr><td colspan="4">No current supply-chain findings.</td></tr>@endforelse
    </tbody></table></div>
</article>
@endif
@endsection
