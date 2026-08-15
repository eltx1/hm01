@extends('emails.layouts.horus')
@section('title', $title)
@section('content')
    <h1 style="margin:0 0 16px;color:#f6f8ff;font-size:24px;line-height:1.25;">{{ $heading }}</h1>
    @foreach($lines as $line)
        <p style="margin:0 0 16px;color:#c7d0e3;font-size:15px;line-height:1.7;">{{ $line }}</p>
    @endforeach
    <p style="margin:24px 0;">
        <a href="{{ $actionUrl }}" style="display:inline-block;border-radius:999px;padding:13px 20px;background:#f1b733;color:#07132e;font-size:14px;font-weight:800;text-decoration:none;">{{ $actionText }}</a>
    </p>
    @if(!empty($afterLines))
        @foreach($afterLines as $line)
            <p style="margin:0 0 12px;color:#9da9c2;font-size:13px;line-height:1.65;">{{ $line }}</p>
        @endforeach
    @endif
    <p style="margin:20px 0 0;color:#9da9c2;font-size:12px;line-height:1.65;word-break:break-word;">If the button does not work, copy and paste this secure link into your browser:<br><a href="{{ $actionUrl }}" style="color:#ffd66b;">{{ $actionUrl }}</a></p>
@endsection
