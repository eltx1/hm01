@extends('layouts.admin')
@section('title', 'Notifications')
@section('heading', 'Notification Center')
@section('content')
<section class="hero"><div><p class="eyebrow">Your inbox</p><h2>Notifications</h2><p>Durable updates about events you are authorized to see. Action Center items remain driven by unresolved source state.</p><div class="status-row"><a class="hm-button-secondary button-link" href="{{ route('notifications.preferences') }}">Preferences</a><a class="hm-button-secondary button-link" href="{{ route('notifications.index', ['unread' => 1]) }}">Unread only</a><form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="hm-button-primary">Mark all read</button></form></div></div></section>
<article class="workspace-section"><div class="compact-list notification-list">
@forelse($notifications as $notification)
<div class="compact-row notification-row @if(!$notification->read_at) unread @endif"><div><div class="status-row"><x-status-badge :status="$notification->severity" /><span class="table-note">{{ $notification->category->label() }} · {{ $notification->created_at->diffForHumans() }}</span></div><strong>{{ $notification->title }}</strong><p>{{ $notification->message }}</p><div class="status-row">@if($notification->actionUrl())<a class="section-anchor" href="{{ $notification->actionUrl() }}">Open relevant page</a>@endif @if($notification->read_at)<form method="POST" action="{{ route('notifications.unread', $notification) }}">@csrf @method('PATCH')<button class="text-button">Mark unread</button></form>@else<form method="POST" action="{{ route('notifications.read', $notification) }}">@csrf @method('PATCH')<button class="text-button">Mark read</button></form>@endif</div></div></div>
@empty<p class="muted">No notifications yet.</p>@endforelse
</div>{{ $notifications->links() }}</article>
@endsection
