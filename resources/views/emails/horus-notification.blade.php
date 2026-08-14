@extends('emails.layouts.horus')
@section('title', $item->title)
@section('content')
<h1 style="margin:0 0 18px;color:#f6f8ff;font-size:28px;line-height:1.2;">{{ $item->title }}</h1>
<p style="margin:0 0 22px;color:#c4ccdc;font-size:15px;line-height:1.7;">{{ $item->message }}</p>
@if($item->actionUrl())
<p style="margin:0 0 24px;"><a href="{{ $item->actionUrl() }}" style="display:inline-block;border-radius:999px;background:#f1b733;color:#071127;padding:12px 19px;text-decoration:none;font-weight:700;">Open relevant page</a></p>
@endif
<p style="margin:0;color:#9da9c2;font-size:12px;line-height:1.6;">This message contains no bank, tax, credential, or internal Support-note data.</p>
@endsection
