@extends('layouts.guest')
@section('title', 'Verify email')
@section('content')
<h1>Verify your email</h1>
<p class="muted">Open the verification link sent to your inbox before continuing. If it has expired or did not arrive, request a new one below.</p>
<div class="wizard-actions">
    <form method="POST" action="{{ route('verification.send') }}">@csrf<button class="hm-button-primary" type="submit" data-submitting-label="Sending…">Send another link</button></form>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">Sign out</button></form>
</div>
@endsection
