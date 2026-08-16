@extends('layouts.admin')
@section('title', 'Bidder Supply Chain')
@section('heading', 'Bidder Supply Chain')
@section('content')
@php($account = $detail['account'])
<section class="hero">
    <div><p class="eyebrow">Compliance / Supply Chain / Bidder Authorizations</p><h2>{{ $account->name }}</h2><p>{{ $account->bidder?->name ?? $account->bidder?->code }} · account-aware production readiness</p></div>
    <div class="hero-stat"><strong>{{ $detail['supply_chain_readiness'] }}</strong><span>supply-chain readiness</span></div>
</section>
<x-control-plane.workspace-tabs :items="collect($tabs)->map(fn ($tab) => ['label' => $tab['label'], 'href' => $tab['href']])->all()" label="Supply chain control center sections" />

<div class="metric-grid">
    <article><span>Ads.txt requirement</span><strong>{{ $detail['ads_txt_requirement'] }}</strong></article>
    <article><span>Configured records</span><strong>{{ $detail['records']->count() }}</strong></article>
    <article><span>Mapped websites</span><strong>{{ $detail['mapped_sites']->count() }}</strong></article>
    <article><span>Remote sellers.json</span><strong>{{ $detail['remote_sellers_json_status'] }}</strong></article>
    <article><span>Financial source</span><strong>{{ $detail['financial_source_readiness'] }}</strong></article>
    <article><span>Privacy</span><strong>{{ $detail['privacy_readiness'] }}</strong></article>
</div>

<article class="workspace-section">
    <p class="eyebrow">Configured authorization</p><h2>Ads.txt records</h2>
    <div class="table-wrap"><table><thead><tr><th>Authorization</th><th>Scope</th><th>Status</th><th>Review</th><th>Remote sellers.json</th><th>Last verified</th></tr></thead><tbody>
    @forelse($detail['records'] as $record)
        <tr><td><code>{{ $record->raw_record }}</code></td><td>{{ $record->site?->primary_domain ?: 'Bidder account-wide' }}</td><td><x-status-badge :status="$record->status" /></td><td><x-status-badge :status="$record->review_status?->value ?? (string) $record->review_status" /></td><td><x-status-badge :status="$record->remote_verification_status?->value ?? (string) $record->remote_verification_status" /></td><td>{{ $record->remote_verified_at?->diffForHumans() ?: $record->last_verified_at?->diffForHumans() ?: 'Never' }}</td></tr>
    @empty<tr><td colspan="6">No bidder ads.txt records are configured.</td></tr>@endforelse
    </tbody></table></div>
</article>

<article class="workspace-section">
    <p class="eyebrow">Eligibility map</p><h2>Websites using this bidder account</h2>
    <div class="table-wrap"><table><thead><tr><th>Website</th><th>Mapping</th><th>Website bidder editor</th></tr></thead><tbody>
    @forelse($detail['mapped_sites'] as $site)
        <tr><td><a class="section-anchor" href="{{ route('admin.compliance.supply-chain.site', $site) }}">{{ $site->primary_domain }}</a></td><td><x-status-badge status="ENABLED" /></td><td><a class="hm-button-secondary" href="{{ route('admin.prebid.ads-txt.index', $site) }}">Manage authorizations</a></td></tr>
    @empty<tr><td colspan="3">This bidder account is not enabled on any website.</td></tr>@endforelse
    </tbody></table></div>
</article>

<article class="workspace-section">
    <p class="eyebrow">Readiness gates</p><h2>Account-level readiness</h2>
    <div class="status-row"><span>Supply chain</span><x-status-badge :status="$detail['supply_chain_readiness']" /><span>Financial source</span><x-status-badge :status="$detail['financial_source_readiness']" /><span>Privacy</span><x-status-badge :status="$detail['privacy_readiness']" /></div>
    @forelse($detail['findings'] as $finding)<div class="finding {{ ($finding['severity'] ?? '') === 'ERROR' ? 'finding-danger' : '' }}"><strong>{{ $finding['code'] ?? 'Finding' }}</strong> — {{ $finding['message'] ?? '' }}</div>@empty<p class="muted">No current bidder supply-chain findings.</p>@endforelse
</article>
@endsection
