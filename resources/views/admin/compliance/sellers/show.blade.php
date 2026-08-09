@extends('layouts.admin')
@section('title', 'Seller '.$seller->seller_id)
@section('heading', 'Seller · '.$seller->seller_id)
@section('content')
<section class="hero"><div><p class="eyebrow">{{ $seller->publisher?->display_name }} · Supply Chain &amp; Compliance</p><h2>{{ $seller->name }}</h2><p>{{ $seller->domain }} · {{ $seller->seller_type }}</p><div class="status-row"><x-status-badge :status="$summary['status']" /><x-status-badge :status="$summary['review_status']" /><x-status-badge :status="$summary['health']" /></div></div><a class="hm-button-secondary button-link" href="{{ route('admin.compliance.sellers.index') }}">All sellers</a></section>

<x-control-plane.workspace-tabs :items="[
    ['label' => 'Ads.txt', 'href' => route('admin.compliance.ads-txt.index'), 'visible' => auth()->user()->hasPermission('supply_chain.ads_txt.view')],
    ['label' => 'Sellers & schain', 'href' => route('admin.compliance.sellers.index')],
]" label="Supply chain compliance sections" />

<section class="metric-grid compliance-metrics" aria-label="Seller identity summary">
    <article><p class="eyebrow">Seller ID</p><strong>{{ $seller->seller_id }}</strong></article>
    <article><p class="eyebrow">Confidentiality</p><strong>{{ $seller->is_confidential ? 'Confidential' : 'Public' }}</strong></article>
    <article><p class="eyebrow">Ads.txt relationship</p><x-status-badge :status="$summary['ads_txt_health']" /></article>
    <article><p class="eyebrow">schain relationship</p><x-status-badge :status="$summary['schain_health']" /></article>
    <article><p class="eyebrow">Last changed</p><strong>{{ $seller->updated_at }}</strong></article>
    <article><p class="eyebrow">Last verified</p><strong>{{ $seller->last_verified_at ?: 'Never' }}</strong></article>
</section>

<section class="detail-grid">
    <article><p class="eyebrow">Generated fragment</p><h2>Exact sellers.json object</h2>@if($summary['json_fragment'])<pre class="compliance-code">{{ $summary['json_fragment'] }}</pre>@else<p class="muted">This declaration is not currently eligible for the public artifact. Resolve the findings and activate it after verification.</p>@endif</article>
    <article><p class="eyebrow">Identity provenance</p><h2>Canonical mapping</h2><p><strong>Paid entity:</strong> {{ $seller->publisher?->legal_name ?: 'Missing' }}</p><p><strong>Business domain:</strong> {{ $seller->domain }}</p><p><strong>Scope:</strong> {{ $seller->site?->primary_domain ?: 'All current websites owned by this publisher' }}</p><p><strong>Public handling:</strong> {{ $seller->is_confidential ? 'Only seller_id, seller_type, and is_confidential=1 are published.' : 'Name and business domain are published.' }}</p></article>
</section>

<article class="workspace-section"><p class="eyebrow">Cross-validation</p><h2>Actionable findings</h2>@forelse($summary['findings'] as $finding)<div class="finding {{ $finding['severity'] === 'ERROR' ? 'finding-danger' : '' }}"><strong>{{ $finding['code'] }}</strong><span>{{ $finding['message'] }}</span></div>@empty<p class="muted">No identity, Ads.txt, sellers.json, or schain mismatch is detected.</p>@endforelse</article>

<article class="workspace-section"><p class="eyebrow">Associated websites</p><h2>Ads.txt → sellers.json → schain</h2>
@forelse($summary['sites'] as $siteSummary)<div class="compact-row"><div><strong>{{ $siteSummary['site']->display_name }}</strong><p>{{ $siteSummary['site']->primary_domain }} · seller {{ $siteSummary['seller_id'] ?: 'not configured' }}</p></div><div class="status-row"><span>Ads.txt</span><x-status-badge :status="$siteSummary['ads_txt_health']" /><span>schain</span><x-status-badge :status="$siteSummary['schain_health']" />@if(auth()->user()->hasPermission('supply_chain.ads_txt.view'))<a href="{{ route('admin.compliance.ads-txt.show', $siteSummary['site']) }}">Inspect</a>@endif</div></div>@empty<p class="muted">No website is associated with this declaration.</p>@endforelse
</article>

@if(auth()->user()->hasPermission('supply_chain.sellers.manage') || auth()->user()->hasPermission('supply_chain.sellers.review'))
<section class="detail-grid">
    @if(auth()->user()->hasPermission('supply_chain.sellers.manage'))
    <article><p class="eyebrow">Structural identity</p><h2>Edit allowed fields</h2><p class="muted">Any structural change disables publication and resets verification. Review the new identity before reactivation.</p>
        <form class="form-stack" method="POST" action="{{ route('admin.compliance.sellers.update', $seller) }}">@csrf @method('PUT')
            <label>Website scope<select class="hm-input" name="site_id"><option value="">All publisher websites</option>@foreach($sites as $site)<option value="{{ $site->id }}" @selected($seller->site_id === $site->id)>{{ $site->primary_domain }}</option>@endforeach</select></label>
            <label>Seller ID<input class="hm-input" name="seller_id" value="{{ $seller->seller_id }}" maxlength="64" required></label>
            <label>Seller type<select class="hm-input" name="seller_type">@foreach(\App\Enums\SellerType::cases() as $type)<option value="{{ $type->value }}" @selected($seller->seller_type === $type->value)>{{ $type->value }}</option>@endforeach</select></label>
            <label>Legal / public name<input class="hm-input" name="name" value="{{ $seller->name }}" required></label>
            <label>Business domain<input class="hm-input" name="domain" value="{{ $seller->domain }}" required></label>
            <label><input type="hidden" name="is_confidential" value="0"><input type="checkbox" name="is_confidential" value="1" @checked($seller->is_confidential)> Confidential public declaration</label>
            <button class="hm-button-secondary">Save and require re-review</button>
        </form>
    </article>
    @endif
    <article><p class="eyebrow">Controlled lifecycle</p><h2>Review and publication</h2>
        @if(auth()->user()->hasPermission('supply_chain.sellers.review'))<form class="form-stack" method="POST" action="{{ route('admin.compliance.sellers.review', $seller) }}">@csrf<label>Review outcome<select class="hm-input" name="review_status"><option value="VERIFIED">Verified</option><option value="REJECTED">Rejected and disabled</option><option value="REVIEW_REQUIRED">Return to review required</option></select></label><button class="hm-button-secondary">Record review</button></form>@endif
        @if(auth()->user()->hasPermission('supply_chain.sellers.manage'))
            @if($summary['status'] === \App\Enums\SellerDeclarationStatus::Disabled && $summary['review_status'] === \App\Enums\SupplyChainReviewStatus::Verified)<form class="inline-form" method="POST" action="{{ route('admin.compliance.sellers.activate', $seller) }}">@csrf @method('PATCH')<button class="hm-button-primary">Activate verified declaration</button></form>@elseif($summary['status'] === \App\Enums\SellerDeclarationStatus::Active)<form class="inline-form danger-zone" method="POST" action="{{ route('admin.compliance.sellers.deactivate', $seller) }}">@csrf @method('PATCH')<button class="hm-button-danger">Deactivate declaration</button></form>@else<p class="muted">Verify this declaration before activation.</p>@endif
        @endif
    </article>
</section>
@endif

<article class="workspace-section"><p class="eyebrow">Immutable evidence</p><h2>Seller history</h2>@forelse($auditEvents as $event)<details class="event"><summary><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong> · {{ $event->created_at }}</summary><div class="detail-grid"><pre class="compliance-code">{{ json_encode($event->old_values ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre><pre class="compliance-code">{{ json_encode($event->new_values ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div></details>@empty<p class="muted">No seller audit history.</p>@endforelse</article>
@endsection
