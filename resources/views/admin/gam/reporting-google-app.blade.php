@extends('layouts.admin')
@section('title', 'Set up Google connection')
@section('heading', 'Set up Google connection')
@section('content')
<section class="hero"><div><p class="eyebrow">Platform setup</p><h2>Activate Google account connection</h2><p>Complete this once for Horus Media. Website administrators can then connect their Google accounts from each website’s Reports section.</p></div></section>
<article class="workspace-section">
    @if($ready)
        <div role="status"><h3>Google application configured</h3><p>Choose a website, open Reports, and select Connect with Google. Account authorization and ad unit verification are still required before reports can sync.</p></div>
        @if(auth()->user()->hasPermission('sites.view'))<a class="hm-button-primary button-link" href="{{ route('admin.sites.index') }}">Choose a website</a>@endif
    @elseif($externallyConfigured)
        <div role="status"><h3>Server configuration needs attention</h3><p>The platform operator must correct the existing Google application configuration before account connection can start.</p></div>
    @else
        <form class="form-stack" method="POST" action="{{ route('admin.gam.reporting.google-app.store') }}" enctype="multipart/form-data">
            @csrf
            <label>Google application file<input class="hm-input" type="file" name="google_app" accept=".json,application/json" required aria-describedby="google-app-help"></label>
            <p id="google-app-help">Choose the original JSON file you downloaded from Google. It is uploaded directly to Horus Media and stored encrypted. You do not need to open it or copy its contents.</p>
            <button class="hm-button-primary">Save Google application</button>
        </form>
    @endif
</article>
@endsection
