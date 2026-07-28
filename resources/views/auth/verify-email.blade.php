@extends('layouts.guest')
@section('title', 'Verify email')
@section('content')
<h1>Verify your email</h1>
<p class="muted">Check your inbox before continuing.</p>
<form method="POST" action="{{ route('verification.send') }}">@csrf<button class="hm-button-primary">Send another link</button></form>
<form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button">Sign out</button></form>
@endsection
