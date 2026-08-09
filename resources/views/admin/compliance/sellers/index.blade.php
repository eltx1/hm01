@extends('layouts.admin')
@section('title', 'Supply Chain Sellers')
@section('heading', 'Supply Chain Control Center')
@section('content')
<section class="hero">
    <div><p class="eyebrow">Supply Chain &amp; Compliance</p><h2>Sellers</h2><p>One reviewed seller identity drives Horus sellers.json, each website’s Ads.txt authorization, and the browser schain object.</p></div>
    <div class="status-row"><x-status-badge :status="$network['healthy'] ? 'HEALTHY' : 'CONFLICT'" /><a class="hm-button-secondary button-link" href="{{ route('admin.compliance.sellers.artifact') }}" target="_blank" rel="noopener">Open exact sellers.json</a></div>
</section>

<x-control-plane.workspace-tabs :items="[
    ['label' => 'Ads.txt', 'href' => route('admin.compliance.ads-txt.index'), 'visible' => auth()->user()->hasPermission('supply_chain.ads_txt.view')],
    ['label' => 'Sellers & schain', 'href' => route('admin.compliance.sellers.index')],
]" label="Supply chain compliance sections" />

<section class="metric-grid compliance-metrics" aria-label="Seller network summary">
    <article><p class="eyebrow">Declarations</p><strong class="metric">{{ $declarations->total() }}</strong></article>
    <article><p class="eyebrow">Published sellers</p><strong class="metric">{{ count($network['payload']['sellers']) }}</strong></article>
    <article><p class="eyebrow">Findings</p><strong class="metric">{{ count($network['findings']) }}</strong></article>
    <article><p class="eyebrow">Spec</p><strong>sellers.json 1.0</strong><p>schain 1.0</p></article>
</section>

@if(auth()->user()->hasPermission('supply_chain.sellers.manage'))
<article class="workspace-section">
    <p class="eyebrow">Reviewed lifecycle</p><h2>Create seller declaration</h2>
    <p class="muted">New declarations start disabled and review-required. No account ID is invented and activation is blocked until verification.</p>
    <form class="form-grid" method="POST" action="{{ route('admin.compliance.sellers.store') }}">@csrf
        <label>Publisher / paid entity<select class="hm-input" name="publisher_id" required><option value="">Select publisher</option>@foreach($publishers as $publisher)<option value="{{ $publisher->id }}">{{ $publisher->display_name }} · {{ $publisher->business_domain ?: 'business domain pending' }}</option>@endforeach</select></label>
        <label>Website scope<select class="hm-input" name="site_id"><option value="">All websites owned by the publisher</option>@foreach($sites as $site)<option value="{{ $site->id }}">{{ $site->publisher?->display_name }} · {{ $site->primary_domain }}</option>@endforeach</select></label>
        <label>Seller ID<input class="hm-input" name="seller_id" maxlength="64" required></label>
        <label>Seller type<select class="hm-input" name="seller_type" required>@foreach(\App\Enums\SellerType::cases() as $type)<option value="{{ $type->value }}">{{ $type->value }}</option>@endforeach</select></label>
        <label>Legal / public name<input class="hm-input" name="name" maxlength="255" required></label>
        <label>Business domain<input class="hm-input" name="domain" maxlength="253" required></label>
        <label><input type="hidden" name="is_confidential" value="0"><input type="checkbox" name="is_confidential" value="1"> Omit name and domain from the public artifact as confidential</label>
        <button class="hm-button-primary">Create for review</button>
    </form>
</article>
@endif

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Canonical identities</p><h2>Seller declarations</h2></div></div>
    <div class="table-wrap"><table><thead><tr><th>Seller ID</th><th>Type</th><th>Legal / public identity</th><th>Publisher / entity</th><th>Scope</th><th>Confidential</th><th>Status</th><th>Verification</th><th>Ads.txt</th><th>schain</th><th>Changed / verified</th><th>Action</th></tr></thead><tbody>
    @forelse($declarations as $seller)
        @php($summary = $summaries[$seller->id])
        <tr>
            <td><code>{{ $seller->seller_id }}</code></td>
            <td>{{ $seller->seller_type }}</td>
            <td><strong>{{ $seller->name }}</strong><br><span class="muted">{{ $seller->domain }}</span></td>
            <td>{{ $seller->publisher?->display_name ?: 'Missing entity' }}</td>
            <td>{{ $seller->site?->primary_domain ?: 'All publisher websites' }}<br><span class="muted">{{ $summary['sites']->count() }} associated</span></td>
            <td>{{ $seller->is_confidential ? 'Yes · public fields omitted' : 'No' }}</td>
            <td><x-status-badge :status="$summary['status']" /></td>
            <td><x-status-badge :status="$summary['review_status']" /></td>
            <td><x-status-badge :status="$summary['ads_txt_health']" /></td>
            <td><x-status-badge :status="$summary['schain_health']" /></td>
            <td>{{ $seller->updated_at?->diffForHumans() }}<br><span class="muted">{{ $seller->last_verified_at?->diffForHumans() ?: 'never verified' }}</span></td>
            <td><a href="{{ route('admin.compliance.sellers.show', $seller) }}">Inspect &amp; remediate</a></td>
        </tr>
    @empty<tr><td colspan="12"><p class="muted">No seller declarations exist.</p></td></tr>@endforelse
    </tbody></table></div>
    {{ $declarations->links() }}
</article>

<section class="detail-grid">
    <article><p class="eyebrow">Actionable consistency</p><h2>Network findings</h2>
        @forelse($network['findings'] as $finding)<div class="finding {{ $finding['severity'] === 'ERROR' ? 'finding-danger' : '' }}"><strong>{{ $finding['code'] }}</strong><span>{{ $finding['message'] }}</span>@if($finding['site_domain'] ?? null)<a href="{{ route('admin.compliance.ads-txt.show', $finding['site_id']) }}">{{ $finding['site_domain'] }}</a>@endif</div>@empty<p class="muted">No sellers.json, Ads.txt, or schain inconsistency is currently detected.</p>@endforelse
    </article>
    <article><div class="workspace-heading"><div><p class="eyebrow">Exact public artifact</p><h2>sellers.json preview</h2></div><button class="hm-button-secondary" type="button" data-copy-target="sellers-json-preview">Copy JSON</button></div><pre id="sellers-json-preview" class="compliance-code">{{ $network['artifact'] }}</pre></article>
</section>
@endsection
