@extends('layouts.guest')
@section('title', 'Choose password')
@section('content')
<h1>Choose a new password</h1>
<form method="POST" action="{{ route('password.update') }}" class="form-stack">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <label>Email<input class="hm-input" type="email" name="email" value="{{ $email }}" required></label>
    <label>Password<input class="hm-input" type="password" name="password" required></label>
    <label>Confirm password<input class="hm-input" type="password" name="password_confirmation" required></label>
    @foreach ($errors->all() as $error) <p class="error">{{ $error }}</p> @endforeach
    <button class="hm-button-primary" type="submit">Reset password</button>
</form>
@endsection
