@extends('layouts.guest')
@section('title', 'Publisher Applications Temporarily Unavailable')
@section('content')
<div class="application-intro">
    <p class="eyebrow">Publisher partnerships</p>
    <h1>Publisher applications are temporarily unavailable</h1>
    <p class="muted">We cannot safely start a new Publisher application right now. No application has been created. Please try again later.</p>
</div>
<div class="notice">
    Existing Horus Media accounts and invitations are not affected.
    @if(config('publisher-applications.support_url'))
        <a class="text-link" href="{{ config('publisher-applications.support_url') }}">Contact Horus Media support</a> if you need assistance.
    @endif
</div>
<a class="hm-button-secondary" href="{{ route('login') }}">Sign in to an existing account</a>
@endsection
