@extends('layouts.admin')
@section('title', 'Configuration recovery preview')
@section('heading', 'Configuration recovery preview')
@section('content')
<section><h2>{{ $site->display_name }} · Source version {{ $configVersion->version }}</h2>
<p>Layout and targeting are restored from this version. Current provider recipes, bidder settings, GAM network, inventory paths and renderer eligibility are retained. Deleted placements are omitted. Current Cloudflare, Click Guard, domains, pause and observation policies remain authoritative.</p>
@if($preview === null)<p>Current supply cannot be validated. Recovery is unavailable until current provider configuration is reviewed.</p>
@else
<p>Selected resulting fields, compared with the historical source:</p>
<div class="table-wrap"><table><thead><tr><th>Field</th><th>Resulting value</th><th>Compared with source</th></tr></thead><tbody>
@foreach($preview['fields'] as $label => $value)<tr><td>{{ $label }}</td><td>{{ $value === null ? 'Unknown' : (is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : $value) }}</td><td>{{ in_array($label, $preview['changed'], true) ? 'Adjusted by current policy' : 'Same recorded value' }}</td></tr>@endforeach
</tbody></table></div>
<p>Applying queues a new version through normal delivery. Current settings are rebuilt when applying; this preview reserves no version. An identical immediate retry reuses the existing recovery version.</p>
@if(auth()->user()->hasPermission('configs.publish'))
<form method="POST" action="{{ route('admin.sites.config.rollback', [$site, $configVersion]) }}">@csrf<button class="hm-button-primary">Queue recovery with current providers and protection</button></form>
@endif
@endif
<a href="{{ route('admin.sites.config.history', $site) }}">Return to configuration history</a></section>
@endsection
