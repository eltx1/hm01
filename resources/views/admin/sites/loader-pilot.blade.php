@extends('layouts.admin')
@section('title', 'Loader pilot release')
@section('heading', 'Loader pilot release')
@section('content')
<section><h2>{{ $site->display_name }}</h2>
<p>Choose one committed immutable build for this website. Default follows the permanent Loader. No publisher snippet change is needed. Use a controlled pilot before wider rollout.</p>
<p>Saved selection: {{ $selection === 'default' ? 'Default' : substr($selection, 0, 16) }} · Confirmed production version: {{ $number ?: 'Unknown' }} · Confirmed pinned runtime: {{ $publishedBuild ?? 'Default or no pinned-build evidence' }}</p>
<p>Saving queues normal static delivery for an active website. It does not establish browser execution. Each page holds its selected configuration; existing pages are not switched mid-page. Older cached bootstrap code must reload before it can honor build pins.</p>
@if(auth()->user()->hasPermission('configs.manage') && auth()->user()->hasPermission('configs.publish'))
<form method="POST" action="{{ route('admin.sites.loader-pilot.update', $site) }}" class="form-stack">@csrf @method('PUT')
<input type="hidden" name="expected_selection" value="{{ $selection }}">
<label>Release<select class="hm-select" name="release"><option value="default" @selected($selection === 'default')>Default permanent Loader</option>@foreach($records as $digest => $record)<option value="{{ $digest }}" @selected($selection === $digest)>{{ $record['version'] }} · {{ substr($digest, 0, 16) }}</option>@endforeach</select></label>
<label>Reason<input class="hm-input" name="reason" minlength="8" maxlength="500" required></label>
<button class="hm-button-primary">Save selection and queue delivery</button></form>
@endif
<p>Return to Default to recover the site through normal delivery. Current admission, Click Guard and provider controls are still authoritative in either build. Archived releases are retained for pinned configurations.</p>
<a href="{{ route('admin.sites.show', $site) }}">Return to website</a></section>
@endsection
