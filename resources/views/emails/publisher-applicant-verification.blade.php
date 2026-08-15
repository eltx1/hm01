@extends('emails.layouts.horus')
@section('title', 'Verify your email')
@section('content')
<p style="margin:0 0 10px;color:#ffd66b;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;">Publisher application</p>
<h1 style="margin:0 0 16px;color:#f6f8ff;font-size:26px;line-height:1.2;">Verify your business email</h1>
<p style="margin:0 0 22px;color:#c9d3e8;line-height:1.7;">Hello {{ $applicant->name }}, verify this email address to continue your Horus Media Publisher application. Verification does not approve the Publisher, a website, or monetization.</p>
<a href="{{ $verificationUrl }}" style="display:inline-block;border-radius:999px;padding:12px 20px;background:#f1b733;color:#071127;font-weight:700;text-decoration:none;">Verify email</a>
<p style="margin:22px 0 0;color:#9da9c2;font-size:12px;line-height:1.6;">If you did not start this application, you can ignore this message. Never share your password or security credentials.</p>
@endsection
