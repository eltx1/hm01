@extends('layouts.admin')
@section('title', 'Historical demand review')
@section('heading', 'Historical demand review')
@section('content')
<section><h2>{{ $site->display_name }} · Version {{ $configVersion->version }}</h2>
<p>Read-only comparison with recipes currently produced by the approved Direct Demand configuration. This does not queue a rollback, execute a provider tag or change advertising permission.</p>
@if($review === null)<p>Current or historical recipe evidence is unavailable. Compatibility is unknown.</p>
@else
<dl><dt>Exact recipes currently reproducible</dt><dd>{{ $review['matched'] }}</dd><dt>Historical candidates not verified against current recipes</dt><dd>{{ $review['unverified'] }}</dd><dt>GAM-managed candidates outside this check</dt><dd>{{ $review['gam_managed_not_checked'] }}</dd></dl>
<p>A changed or removed recipe is unverified; this alone does not prove it is unsafe. Matching recipes do not establish compatibility of the whole historical configuration.</p>
@endif
<p>GAM eligibility, Prebid, house creatives, renderer ownership and concurrent changes require separate validation. This review is not approval to apply a rollback. Current Cloudflare, Click Guard, domain, pause and observation settings must remain authoritative.</p>
<a href="{{ route('admin.sites.config.history', $site) }}">Return to configuration history</a></section>
@endsection
