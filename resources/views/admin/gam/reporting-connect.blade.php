@extends('layouts.admin')
@section('title', 'Connect Ad Manager reports')
@section('heading', 'Connect Ad Manager reports')
@section('content')
<section class="hero"><div><p class="eyebrow">{{ $site->display_name }}</p><h2>Connect your Google account</h2><p>Choose your Google account, then the ad unit for this website. Horus requests reporting revenue in US Dollar (USD) regardless of the network's native currency.</p></div><a class="hm-button-secondary button-link" href="{{ route('admin.sites.show', $site) }}#reporting">Back to website</a></section>

@if($accountEmail && count($networks))
<article class="workspace-section">
    <p class="eyebrow">Google account verified</p><h3>Choose your Ad Manager networks</h3><p>{{ $accountEmail }}</p>
    @if($adUnit !== '')<p>Choose the network containing <strong>{{ $adUnit }}</strong>. We will verify the unit and connect reports automatically.</p>@endif
    <form method="POST" action="{{ route('admin.sites.reporting.accounts.connect', $site) }}" class="form-stack">
        @csrf<input type="hidden" name="flow" value="{{ $flow }}">
        @foreach($networks as $network)
        <label class="compact-row"><span><input type="{{ $adUnit !== '' ? 'radio' : 'checkbox' }}" name="networks[]" value="{{ $network['code'] }}" @checked(in_array($network['code'], old('networks', [])))> <strong>{{ $network['name'] }}</strong></span><span>{{ $network['code'] }} · {{ $network['currency'] }} · {{ $network['timezone'] }}</span></label>
        @endforeach
        <small>{{ $adUnit !== '' ? 'Select the network for this website. The currency shown above is Google network metadata; Horus reports in USD.' : 'Select one or more. Each network stays available in the account picker for your websites; reporting revenue is requested in USD.' }}</small>
        <button class="hm-button-primary">{{ $adUnit !== '' ? 'Connect reports' : 'Add selected networks and continue' }}</button>
    </form>
</article>
@else
<article class="workspace-section">
    @include('admin.gam.reporting-google-button')
</article>
@endif
@endsection
