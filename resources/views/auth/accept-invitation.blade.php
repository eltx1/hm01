@extends('layouts.guest')
@section('title', 'Accept invitation')
@section('content')
<h1>Activate your account</h1>
<form method="POST" action="{{ route('invitations.accept', $token) }}" class="form-stack">
    @csrf
    <label>Full name<input class="hm-input" name="name" required></label>
    <label>Password<input class="hm-input" type="password" name="password" required></label>
    <label>Confirm password<input class="hm-input" type="password" name="password_confirmation" required></label>
    @foreach ($errors->all() as $error) <p class="error">{{ $error }}</p> @endforeach
    <button class="hm-button-primary" type="submit">Activate account</button>
</form>
@endsection
