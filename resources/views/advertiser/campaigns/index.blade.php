@extends('layouts.admin')
@section('title', 'Campaigns')
@section('heading', 'Direct campaigns')
@section('navigation')
<a class="active" href="{{ route('advertiser.campaigns.index') }}">Campaigns</a><a href="#billing">Billing</a><a href="#invoices">Invoices</a><a href="{{ route('dashboard') }}">Overview</a>
@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">Advertiser workspace</p><h2>{{ $advertiser->display_name }}</h2><p>Create, submit, monitor, and invoice direct campaigns without Google Ad Manager access.</p></div><a class="hm-button-primary" href="{{ route('advertiser.campaigns.create') }}">Create campaign</a></section>
<section class="metric-grid" style="margin-top:1rem">
@foreach([['Campaigns',$campaigns->total()],['Open invoices',$invoices->whereNotIn('status',['PAID','VOID'])->count()],['Account status',$advertiser->status->value]] as [$label,$value])<article><p class="eyebrow">{{ $label }}</p><strong class="metric-small">{{ $value }}</strong></article>@endforeach
</section>
<article><div class="section-heading"><div><p class="eyebrow">Local source of truth</p><h2>Your campaigns</h2></div></div>
@forelse($campaigns as $campaign)<div class="domain-card"><div><strong><a href="{{ route('advertiser.campaigns.show',$campaign) }}">{{ $campaign->name }}</a></strong><div class="status-row"><span class="pill">{{ $campaign->status->value }}</span><span class="pill">{{ $campaign->pricing_model->value }}</span><span class="pill">{{ $campaign->sites_count }} sites</span><span class="pill">{{ $campaign->network_instances_count }} GAM networks</span></div></div><div>{{ $campaign->currency }} {{ number_format($campaign->total_budget_minor/100,2) }}</div></div>@empty<p class="muted">No campaigns yet.</p>@endforelse
{{ $campaigns->links() }}</article>
<section class="detail-grid">
<article id="billing"><p class="eyebrow">Billing</p><h2>Billing profile</h2><form class="form-stack" method="POST" action="{{ route('advertiser.billing-profile.store') }}">@csrf
<label>Legal name<input class="hm-input" name="legal_name" value="{{ $advertiser->legal_name }}" required></label><label>Billing email<input class="hm-input" type="email" name="billing_email" value="{{ $advertiser->billing_email }}" required></label>
<div class="form-grid"><label>Currency<input class="hm-input" name="currency" value="USD" maxlength="3" required></label><label>Country<input class="hm-input" name="country_code" maxlength="2" required></label><label class="full">Address<input class="hm-input" name="address_line_1" required></label><label>City<input class="hm-input" name="city" required></label><label>Region<input class="hm-input" name="region"></label><label>Postal code<input class="hm-input" name="postal_code"></label><label>Tax identifier<input class="hm-input" name="tax_identifier"></label><label>Payment terms days<input class="hm-input" type="number" min="0" max="365" name="payment_terms_days" value="0"></label></div>
<input type="hidden" name="is_default" value="1"><button class="hm-button-secondary">Save billing profile</button></form></article>
<article id="invoices"><p class="eyebrow">Documents</p><h2>Invoices</h2>@forelse($invoices as $invoice)<div class="event"><div><strong>{{ $invoice->invoice_number }}</strong><br><span>{{ $invoice->currency }} {{ number_format($invoice->total_minor/100,2) }} · {{ $invoice->status->value }}</span></div><a class="hm-button-secondary" href="{{ route('advertiser.invoices.download',$invoice) }}">Download</a></div>@empty<p class="muted">Invoices are issued when campaigns are approved.</p>@endforelse</article>
</section>
@endsection
