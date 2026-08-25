@extends('layouts.admin')
@php($adminContext = $adminContext ?? false)
@section('title', $site->exists ? 'Edit website' : ($adminContext ? 'Add website to '.$publisher->display_name : 'Add website'))
@section('heading', $site->exists ? 'Edit website' : ($adminContext ? 'Add website to '.$publisher->display_name : 'Add website'))
@section('content')
<article><p class="muted">Every new website starts as a separate draft for {{ $publisher->display_name }}. Successful ads.txt verification submits it for Horus review automatically.</p>
<form method="POST" action="{{ $site->exists ? route('publisher.sites.update', $site) : ($siteStoreRoute ?? route('publisher.sites.store')) }}" class="form-grid">@csrf @if($site->exists)@method('PUT')@endif
<label>Display name<input class="hm-input" name="display_name" value="{{ old('display_name', $site->display_name) }}" required></label>
<label>Primary domain<input class="hm-input" name="primary_domain" value="{{ old('primary_domain', $site->primary_domain) }}" placeholder="example.com" required></label>
@if(!$site->exists)
<label>Content category<select class="hm-input" name="content_category" required><option value="">Choose one</option>@foreach(['NEWS','ENTERTAINMENT','SPORTS','TECHNOLOGY','LIFESTYLE','BUSINESS','OTHER'] as $category)<option value="{{ $category }}" @selected(old('content_category') === $category)>{{ str($category)->headline() }}</option>@endforeach</select></label>
<label>Primary country (ISO 2)<input class="hm-input" name="country" value="{{ old('country') }}" maxlength="2" placeholder="US" required></label>
<div class="full wizard-actions"><span class="muted">Next: copy one complete ads.txt block and verify only the two Horus records.</span><div>@if(isset($cancelRoute))<a class="section-anchor" href="{{ $cancelRoute }}">Cancel</a>@endif <button class="hm-button-primary">Add website</button></div></div>
@else
<label>Language<input class="hm-input" name="language" value="{{ old('language', $site->language ?: 'en') }}" required></label>
<label>Content category<input class="hm-input" name="content_category" value="{{ old('content_category', $site->content_category) }}" required></label>
<label>Country (ISO 2)<input class="hm-input" name="country" value="{{ old('country', $site->country) }}" maxlength="2" required></label>
<label>Main traffic countries<input class="hm-input" name="main_traffic_countries" value="{{ old('main_traffic_countries', implode(',', $site->main_traffic_countries ?? [])) }}" placeholder="US,GB,CA"></label>
<label>Estimated monthly pageviews<input class="hm-input" type="number" min="0" name="estimated_monthly_pageviews" value="{{ old('estimated_monthly_pageviews', $site->estimated_monthly_pageviews ?? 0) }}" required></label>
<label>Estimated monthly users<input class="hm-input" type="number" min="0" name="estimated_monthly_users" value="{{ old('estimated_monthly_users', $site->estimated_monthly_users ?? 0) }}" required></label>
<label>Current monetization providers<input class="hm-input" name="current_monetization_providers" value="{{ old('current_monetization_providers', implode(',', $site->current_monetization_providers ?? [])) }}" placeholder="AdSense,Other"></label>
<label>Current GAM network code<input class="hm-input" name="current_gam_network_code" value="{{ old('current_gam_network_code', $site->current_gam_network_code) }}"></label>
<label>AdSense status<input class="hm-input" name="current_adsense_status" value="{{ old('current_adsense_status', $site->current_adsense_status) }}"></label>
<label>AdX status<input class="hm-input" name="current_adx_status" value="{{ old('current_adx_status', $site->current_adx_status) }}"></label>
<label>Default revenue share<input class="hm-input" value="{{ $site->exists ? $site->default_revenue_share_percent : $publisher->applicableRevenueShare() }}%" disabled><span class="muted">Controlled by approved commercial terms and more-specific revenue rules.</span></label>
<label><input type="hidden" name="prebid_enabled" value="0"><input type="checkbox" name="prebid_enabled" value="1" @checked(old('prebid_enabled', $site->prebid_enabled))> Enable Prebid when configuration is published</label>
<label><input type="hidden" name="native_demand_enabled" value="0"><input type="checkbox" name="native_demand_enabled" value="1" @checked(old('native_demand_enabled', $site->native_demand_enabled))> Enable optional native demand</label>
<button class="hm-button-primary">Save website</button>
@endif
</form></article>
@endsection
