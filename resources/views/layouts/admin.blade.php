<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($brand = auth()->user()?->organization)
    @php($brandIdentity = app(\App\Support\Branding\BrandIdentityResolver::class)->forWorkspace(auth()->user()))
    @php($isHorusWorkspace = $brand?->type === \App\Enums\OrganizationType::HorusMedia)
    @php($navigationGroups = auth()->check() ? app(\App\Services\ControlPlane\ControlPlaneNavigation::class)->for(auth()->user()) : [])
    @php($notificationPreview = auth()->check() && auth()->user()->hasPermission('notifications.view_own') ? auth()->user()->horusNotifications()->where('in_app_visible', true)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get() : collect())
    @php($unreadNotifications = $notificationPreview->whereNull('read_at')->count() + (auth()->check() && auth()->user()->hasPermission('notifications.view_own') ? auth()->user()->horusNotifications()->where('in_app_visible', true)->unread()->whereNotIn('id', $notificationPreview->pluck('id'))->count() : 0))
    <title>@yield('title', 'Dashboard') · {{ $brandIdentity->name }}</title>
    <x-brand.favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @if(! $isHorusWorkspace && $brand?->primary_color)style="--hm-tenant-accent: {{ $brand->primary_color }}"@endif>
    <div class="admin-shell">
        <aside class="sidebar" id="control-navigation" aria-label="Primary navigation">
            <x-brand.product-lockup context="workspace" variant="emblem" :href="url('/')" class="sidebar-brand" />
            <p class="eyebrow">Ad Network Control Plane</p>
            <x-control-plane.navigation :groups="$navigationGroups" />
            <div class="sidebar-account">
                <span>{{ auth()->user()->name }}</span>
                <small>{{ auth()->user()->email }}</small>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button">Sign out</button></form>
            </div>
        </aside>
        <button class="sidebar-scrim" type="button" data-nav-close aria-label="Close navigation"></button>
        <main>
            <header class="topbar">
                <button class="mobile-nav-toggle" type="button" data-nav-toggle aria-controls="control-navigation" aria-expanded="false"><span aria-hidden="true">☰</span><span class="sr-only">Open navigation</span></button>
                <x-brand.product-lockup context="workspace" variant="header" :href="url('/')" :compact="true" class="mobile-product-lockup" />
                <div class="topbar-title">
                    <p class="eyebrow">app.horusmedia.net</p>
                    <h1>@yield('heading', 'Dashboard')</h1>
                </div>
                @if(auth()->user()->hasPermission('notifications.view_own'))
                <details class="notification-bell"><summary aria-label="Notifications">🔔 @if($unreadNotifications)<span>{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>@endif</summary><div class="notification-popover"><strong>Latest notifications</strong>@forelse($notificationPreview as $item)<a href="{{ route('notifications.index') }}"><span>{{ $item->title }}</span><small>{{ $item->created_at->diffForHumans() }}</small></a>@empty<p class="muted">No notifications yet.</p>@endforelse<a class="section-anchor" href="{{ route('notifications.index') }}">Open Notification Center</a></div></details>
                @endif
                <span class="status">Control Plane</span>
            </header>
            @if(session()->has('impersonator_id'))
                <form method="POST" action="{{ route('admin.impersonate.stop') }}" class="impersonation-banner">@csrf @method('DELETE') Impersonating {{ auth()->user()->email }} <button>Stop</button></form>
            @endif
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if(session('error'))<div class="notice error" role="alert">{{ session('error') }}</div>@endif
            @if($errors->any())<div class="notice error" role="alert"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
</body>
</html>
