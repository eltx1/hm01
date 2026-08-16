@extends('layouts.admin')
@section('title', 'Campaigns')
@section('heading', 'Direct campaigns')
@section('content')
<section class="hero"><div><p class="eyebrow">Advertiser workspace</p><h2>{{ $advertiser->display_name }}</h2><p>Create, submit, monitor, and invoice direct campaigns through Horus-managed delivery. You do not need to own or manage the underlying delivery account.</p></div>@if($campaignCreationEnabled)<a class="hm-button-primary" href="{{ route('advertiser.campaigns.create') }}">Create campaign</a>@endif</section>
@if(!$campaignCreationEnabled)<article class="danger-zone" style="margin-top:1rem"><h3>Campaign delivery is currently unavailable</h3><p>Existing campaigns, invoices, and history remain available. New campaign creation is temporarily disabled.</p></article>@endif
<section class="metric-grid" style="margin-top:1rem">
    <article><p class="eyebrow">Campaigns</p><strong class="metric-small">{{ $campaigns->total() }}</strong></article>
    <article><p class="eyebrow">Open invoices</p><strong class="metric-small">{{ $invoices->whereNotIn('status',['PAID','VOID'])->count() }}</strong></article>
    <article><p class="eyebrow">Account status</p><x-status-badge :status="$advertiser->status" /></article>
</section>
<article>
    <div class="section-heading"><div><p class="eyebrow">Campaign workspace</p><h2>Your campaigns</h2></div></div>
    @if($campaigns->count() === 0)
        <x-empty-state title="No campaigns yet" description="Campaign drafts define budget, pricing, targeting, and sites before Horus Media review.">
            @if($campaignCreationEnabled)<a class="hm-button-primary" href="{{ route('advertiser.campaigns.create') }}">Create your first campaign</a>@endif
        </x-empty-state>
    @else
        @foreach($campaigns as $campaign)
            <div class="domain-card">
                <div><strong><a class="section-anchor" href="{{ route('advertiser.campaigns.show',$campaign) }}">{{ $campaign->name }}</a></strong><div class="status-row"><x-status-badge :status="$campaign->status" /><span class="pill">{{ str($campaign->pricing_model->value)->replace('_', ' ')->headline() }}</span><span class="pill">{{ $campaign->sites_count }} sites</span><span class="pill">{{ $campaign->network_instances_count }} delivery route(s)</span></div></div>
                <div class="money" aria-label="Campaign budget">{{ $campaign->currency }} {{ number_format($campaign->total_budget_minor/100,2) }}</div>
            </div>
        @endforeach
        {{ $campaigns->links() }}
    @endif
</article>
<section class="detail-grid">
<article id="billing"><p class="eyebrow">Billing</p><h2>Billing profile</h2><p class="muted">Keep invoice details current. Validation errors preserve the values you entered.</p><form class="form-stack" method="POST" action="{{ route('advertiser.billing-profile.store') }}">@csrf
<label for="billing-legal-name">Legal name<input id="billing-legal-name" class="hm-input" name="legal_name" value="{{ old('legal_name', $advertiser->legal_name) }}" required autocomplete="organization"></label>
<label for="billing-email">Billing email<input id="billing-email" class="hm-input" type="email" name="billing_email" value="{{ old('billing_email', $advertiser->billing_email) }}" required autocomplete="email" inputmode="email"></label>
<div class="form-grid">
<label for="billing-currency">Currency<input id="billing-currency" class="hm-input" name="currency" value="{{ old('currency', 'USD') }}" maxlength="3" required autocapitalize="characters"></label>
<label for="billing-country">Country<input id="billing-country" class="hm-input" name="country_code" value="{{ old('country_code') }}" maxlength="2" required autocomplete="country" autocapitalize="characters"></label>
<label class="full" for="billing-address">Address<input id="billing-address" class="hm-input" name="address_line_1" value="{{ old('address_line_1') }}" required autocomplete="address-line1"></label>
<label for="billing-city">City<input id="billing-city" class="hm-input" name="city" value="{{ old('city') }}" required autocomplete="address-level2"></label>
<label for="billing-region">Region<input id="billing-region" class="hm-input" name="region" value="{{ old('region') }}" autocomplete="address-level1"></label>
<label for="billing-postal">Postal code<input id="billing-postal" class="hm-input" name="postal_code" value="{{ old('postal_code') }}" autocomplete="postal-code"></label>
<label for="billing-tax-id">Tax identifier<input id="billing-tax-id" class="hm-input" name="tax_identifier" value="{{ old('tax_identifier') }}" autocomplete="off"></label>
<label for="billing-terms">Payment terms days<input id="billing-terms" class="hm-input" type="number" min="0" max="365" name="payment_terms_days" value="{{ old('payment_terms_days', 0) }}"></label>
</div>
<input type="hidden" name="is_default" value="1"><button class="hm-button-secondary" type="submit" data-submitting-label="Saving…">Save billing profile</button></form></article>
<article id="invoices"><p class="eyebrow">Documents</p><h2>Invoices</h2>
@if($invoices->count() === 0)
    <x-empty-state title="No invoices yet" description="Invoices appear here after eligible campaigns are approved and billed." />
@else
    @foreach($invoices as $invoice)<div class="event"><div><strong>{{ $invoice->invoice_number }}</strong><br><span class="money">{{ $invoice->currency }} {{ number_format($invoice->total_minor/100,2) }}</span> · <x-status-badge :status="$invoice->status" /></div><a class="hm-button-secondary" href="{{ route('advertiser.invoices.download',$invoice) }}">Download invoice</a></div>@endforeach
@endif
</article>
</section>
@endsection
