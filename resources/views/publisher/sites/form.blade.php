@extends('layouts.admin')
@php($adminContext = $adminContext ?? false)
@php($siteCountry = old('country', $site->country ?: ''))
@php($countryOptions = config('countries', []) + ($siteCountry ? [$siteCountry => $siteCountry] : []))
@section('title', $site->exists ? 'Edit website' : ($adminContext ? 'Add website to '.$publisher->display_name : 'Add website'))
@section('heading', $site->exists ? 'Edit website' : ($adminContext ? 'Add website to '.$publisher->display_name : 'Add website'))
@section('content')
<div class="ui-page">
    <div class="ui-page-intro"><div><h2>{{ $site->exists ? 'Your website details' : 'Let’s add your website' }}</h2><p>{{ $site->exists ? 'Keep your website and audience information up to date.' : 'Start with the basics. We’ll guide you through verification next.' }}</p></div></div>
    <div class="ui-settings-layout">
        <form method="POST" action="{{ $site->exists ? route('publisher.sites.update', $site) : ($siteStoreRoute ?? route('publisher.sites.store')) }}" class="ui-form-surface">
            @csrf @if($site->exists)@method('PUT')@endif
            <x-form-section number="01" title="Website details" description="Add the name and domain for this website.">
                <div class="ui-fields">
                    <x-form-field name="display_name" label="Website name" :value="old('display_name', $site->display_name)" required placeholder="Your website name" />
                    <x-form-field name="primary_domain" label="Primary domain" :value="old('primary_domain', $site->primary_domain)" required placeholder="example.com" help="Enter the domain without a page path." />
                </div>
            </x-form-section>
            <x-form-section number="02" title="Content and audience" description="Help us understand your website’s primary audience.">
                <div class="ui-fields">
                    @if(! $site->exists)
                        <x-form-field name="content_category" label="Content category" as="select" :value="old('content_category')" :options="collect(['NEWS','ENTERTAINMENT','SPORTS','TECHNOLOGY','LIFESTYLE','BUSINESS','OTHER'])->mapWithKeys(fn ($category) => [$category => str($category)->headline()->toString()])->all()" required placeholder="Choose a category" />
                    @else
                        <x-form-field name="content_category" label="Content category" :value="old('content_category', $site->content_category)" required />
                        <x-form-field name="language" label="Primary language" :value="old('language', $site->language ?: 'en')" required placeholder="en" help="Language code, for example en or ar." />
                    @endif
                    <x-form-field name="country" label="Primary country or territory" as="select" :value="$siteCountry" :options="$countryOptions" required placeholder="Select a country" />
                    @if($site->exists)
                        <x-form-field name="main_traffic_countries" label="Main traffic countries" :value="old('main_traffic_countries', implode(',', $site->main_traffic_countries ?? []))" placeholder="US,GB,CA" help="Separate country codes with commas." />
                        <x-form-field name="estimated_monthly_pageviews" label="Monthly pageviews" type="number" min="0" :value="old('estimated_monthly_pageviews', $site->estimated_monthly_pageviews ?? 0)" required help="Your latest estimate." />
                        <x-form-field name="estimated_monthly_users" label="Monthly users" type="number" min="0" :value="old('estimated_monthly_users', $site->estimated_monthly_users ?? 0)" required help="Your latest estimate." />
                    @endif
                </div>
            </x-form-section>
            @if($site->exists)
            <x-form-section number="03" title="Monetization details" description="Update the information associated with this website’s monetization setup.">
                <div class="ui-fields">
                    <x-form-field name="current_monetization_providers" label="Current monetization providers" :value="old('current_monetization_providers', implode(',', $site->current_monetization_providers ?? []))" placeholder="AdSense,Other" help="Separate provider names with commas." />
                    <x-form-field name="current_gam_network_code" label="Current GAM network code" :value="old('current_gam_network_code', $site->current_gam_network_code)" />
                    <x-form-field name="current_adsense_status" label="AdSense status" :value="old('current_adsense_status', $site->current_adsense_status)" />
                    <x-form-field name="current_adx_status" label="AdX status" :value="old('current_adx_status', $site->current_adx_status)" />
                    <x-form-field class="full" name="revenue_share_display" label="Default revenue share" :value="$site->default_revenue_share_percent.'%'" disabled help="Controlled by approved commercial terms and more-specific revenue rules." />
                </div>
                <div class="ui-inline-note form-stack">
                    <label class="check"><input type="hidden" name="prebid_enabled" value="0"><input type="checkbox" name="prebid_enabled" value="1" @checked(old('prebid_enabled', $site->prebid_enabled))> Enable Prebid when configuration is published</label>
                    <label class="check"><input type="hidden" name="native_demand_enabled" value="0"><input type="checkbox" name="native_demand_enabled" value="1" @checked(old('native_demand_enabled', $site->native_demand_enabled))> Enable optional native demand</label>
                </div>
            </x-form-section>
            @endif
            <x-form-actions :cancel-href="$cancelRoute ?? route('publisher.sites.index')" :help="$site->exists ? 'Changes apply to this website.' : 'Next: install your ads.txt and verify the website.'">
                <button class="hm-button-primary" type="submit">{{ $site->exists ? 'Save website' : 'Add website' }}</button>
            </x-form-actions>
        </form>
        <aside class="ui-form-aside">
            <section class="ui-aside-card"><span class="ui-aside-icon"><x-ui-icon name="shield" /></span><h2>{{ $site->exists ? 'Website profile' : 'What happens next?' }}</h2>
                @if(! $site->exists)<ol class="ui-guidance-list"><li>Your website is saved as a separate draft for {{ $publisher->display_name }}.</li><li>Copy the complete ads.txt block provided for your website.</li><li>Successful ads.txt verification submits it for Horus review automatically.</li></ol>
                @else<p>Keep these details accurate so your team can recognize and manage the correct website.</p>@endif
            </section>
        </aside>
    </div>
</div>
@endsection
