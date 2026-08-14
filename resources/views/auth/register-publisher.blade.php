@extends('layouts.guest')
@section('title', 'Apply as a Publisher')
@section('content')
<h1>Apply as a Publisher</h1>
<p class="muted">Start with the essentials. You can verify your email, save your application, and return later.</p>
<div class="notice"><strong>Important:</strong> an application is not Publisher approval, website approval, or production monetization.</div>
@if($errors->any())<div class="notice error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="POST" action="{{ route('publisher-registration.store') }}" class="form-stack">
    @csrf
    <label>Full name<input class="hm-input" name="name" value="{{ old('name') }}" required autocomplete="name" maxlength="255"></label>
    <label>Business email<input class="hm-input" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" maxlength="255"></label>
    <label>Publisher or company name<input class="hm-input" name="publisher_name" value="{{ old('publisher_name') }}" required maxlength="255"></label>
    <label>Primary website or domain<input class="hm-input" name="primary_domain" value="{{ old('primary_domain') }}" required placeholder="example.com" maxlength="500"></label>
    <label>Password<input class="hm-input" type="password" name="password" required autocomplete="new-password"></label>
    <small class="muted">Use at least 14 characters with upper/lowercase letters, a number, and a symbol.</small>
    <label>Confirm password<input class="hm-input" type="password" name="password_confirmation" required autocomplete="new-password"></label>
    <button class="hm-button-primary" type="submit">Create application</button>
    <a class="text-link" href="{{ route('login') }}">Already started? Sign in</a>
</form>
@endsection
