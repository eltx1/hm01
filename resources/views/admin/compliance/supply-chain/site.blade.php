@extends('layouts.admin')
@section('title', 'Website Supply Chain')
@section('heading', 'Website Supply Chain')
@section('content')
@php($site = $detail['site'])
<section class="hero">
    <div><p class="eyebrow">Compliance / Supply Chain / Websites</p><h2>{{ $site->display_name }}</h2><p><code>{{ $site->primary_domain }}</code> · {{ $site->publisher?->display_name }}</p></div>
    <div class="hero-stat"><strong>{{ $detail['seller_id'] ?: '—' }}</strong><span>Horus Seller ID</span></div>
</section>
<x-control-plane.workspace-tabs :items="collect($tabs)->map(fn ($tab) => ['label' => $tab['label'], 'href' => $tab['href']])->all()" label="Supply chain control center sections" />

<div class="metric-grid">
    <article><span>OWNERDOMAIN</span><strong>{{ $detail['owner_domain'] ?: '—' }}</strong></article>
    <article><span>MANAGERDOMAIN</span><strong>{{ $detail['manager_domain'] ?: 'Not applicable' }}</strong></article>
    <article><span>Ads.txt</span><strong>{{ $detail['status'] }}</strong><small>{{ $detail['last_verification']?->diffForHumans() ?: 'Never verified' }}</small></article>
    <article><span>sellers.json consistency</span><strong>{{ $detail['sellers_json_consistency']['status'] }}</strong></article>
    <article><span>Managed deployment</span><strong>{{ $detail['managed_redirect']['mode'] }}</strong><small>{{ $detail['managed_redirect']['status'] }}</small></article>
    <article><span>Last static publication</span><strong>{{ data_get($detail, 'last_static_publication.delivered_at')?->diffForHumans() ?? 'Never' }}</strong></article>
</div>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Public canonical file</p><h2>Canonical ads.txt</h2><p>{{ $detail['action'] }}</p></div><div class="button-row"><a class="hm-button-secondary" href="{{ route('admin.compliance.ads-txt.download', $site) }}">Download canonical file</a>@if(auth()->user()->hasPermission('supply_chain.ads_txt.verify'))<form method="POST" action="{{ route('admin.compliance.ads-txt.verify', $site) }}">@csrf<button class="hm-button-primary">Run live verification</button></form>@endif</div></div>
    <pre class="compliance-code">{{ $detail['canonical']['content'] }}</pre>
</article>

<article class="workspace-section">
    <p class="eyebrow">Why is this here?</p><h2>Canonical line provenance</h2>
    <p class="muted">Every line in the generated public file is mapped back to its internal source and scope.</p>
    <div class="table-wrap"><table><thead><tr><th>Public line</th><th>Source</th><th>Scope</th><th>Why</th><th>Source ID</th></tr></thead><tbody>
    @foreach($detail['provenance'] as $source)
        <tr><td><code>{{ $source['line'] }}</code></td><td>{{ $source['source'] }}</td><td>{{ $source['scope'] ?? '—' }}</td><td>{{ $source['why'] }}</td><td><code>{{ $source['source_id'] ?? '—' }}</code></td></tr>
    @endforeach
    </tbody></table></div>
</article>

<article class="workspace-section">
    <p class="eyebrow">Latest public fetch</p><h2>Live ads.txt</h2>
    @if($detail['live_content'] !== null)<pre class="compliance-code">{{ $detail['live_content'] }}</pre>@else<p class="muted">No live ads.txt body has been verified yet.</p>@endif
    <div class="compliance-diff-grid">
        <div><h3>Missing lines</h3>@forelse($detail['missing'] as $item)<code class="record-line record-missing">{{ $item['canonical'] ?? $item['content'] ?? $item['message'] ?? json_encode($item) }}</code>@empty<p class="muted">None.</p>@endforelse</div>
        <div><h3>Extra lines</h3>@forelse($detail['extra'] as $item)<code class="record-line">{{ $item['canonical'] ?? $item['content'] ?? json_encode($item) }}</code>@empty<p class="muted">None.</p>@endforelse</div>
        <div><h3>Conflicts / invalid</h3>@forelse($detail['conflicts'] as $item)<div class="finding finding-danger"><strong>{{ $item['code'] ?? 'Conflict' }}</strong> {{ $item['message'] ?? $item['content'] ?? $item['canonical'] ?? json_encode($item) }}</div>@empty<p class="muted">None.</p>@endforelse</div>
    </div>
</article>

<article class="workspace-section">
    <p class="eyebrow">Authorization sources</p><h2>Records composing this website</h2>
    <div class="compliance-diff-grid">
        <div><h3>Master records</h3>@forelse($detail['master_records'] as $record)<code class="record-line">{{ $record['canonical'] }}</code>@empty<p class="muted">None.</p>@endforelse</div>
        <div><h3>Bidder records</h3>@forelse($detail['bidder_records'] as $record)<code class="record-line">{{ $record['canonical'] }}</code>@empty<p class="muted">None.</p>@endforelse</div>
        <div><h3>Demand records</h3>@forelse($detail['demand_records'] as $record)<code class="record-line">{{ $record['canonical'] }}</code>@empty<p class="muted">None.</p>@endforelse</div>
    </div>
</article>

<article class="workspace-section">
    <p class="eyebrow">Cross-artifact identity</p><h2>sellers.json and schain</h2>
    <div class="status-row"><span>Consistency</span><x-status-badge :status="$detail['sellers_json_consistency']['status']" /><span>Seller ID</span><code>{{ $detail['seller_id'] ?: '—' }}</code></div>
    <h3>SChain</h3><pre class="compliance-code">{{ json_encode($detail['schain'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @if($detail['findings'])<h3>Consistency findings</h3>@foreach($detail['findings'] as $finding)<div class="finding {{ ($finding['severity'] ?? '') === 'ERROR' ? 'finding-danger' : '' }}"><strong>{{ $finding['code'] ?? 'Finding' }}</strong> — {{ $finding['message'] ?? '' }}</div>@endforeach @endif
</article>

<article class="workspace-section">
    <p class="eyebrow">Managed ads.txt deployment</p><h2>{{ $detail['managed_redirect']['mode'] }}</h2>
    <p>Canonical target: <code>{{ $detail['managed_redirect']['target'] }}</code></p>
    <div class="status-row"><span>Redirect verification</span><x-status-badge :status="$detail['managed_redirect']['status']" /><span>{{ $detail['managed_redirect']['verified_at']?->diffForHumans() ?: 'Never verified' }}</span></div>
</article>
@endsection
