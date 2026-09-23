<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    @php($brand = auth()->user()?->organization)
    @php($brandIdentity = app(\App\Support\Branding\BrandIdentityResolver::class)->forWorkspace(auth()->user()))
    @php($isHorusWorkspace = $brand?->type === \App\Enums\OrganizationType::HorusMedia)
    @php($navigationGroups = auth()->check() ? app(\App\Services\ControlPlane\ControlPlaneNavigation::class)->for(auth()->user()) : [])
    @php($workspaceLabel = match($brand?->type) {
        \App\Enums\OrganizationType::HorusMedia => 'Horus Admin',
        \App\Enums\OrganizationType::Publisher => 'Publisher Workspace',
        \App\Enums\OrganizationType::Advertiser => 'Advertiser Workspace',
        \App\Enums\OrganizationType::Partner => 'Partner Workspace',
        default => 'Workspace',
    })
    @php($notificationPreview = auth()->check() && auth()->user()->hasPermission('notifications.view_own') ? auth()->user()->horusNotifications()->where('in_app_visible', true)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get() : collect())
    @php($unreadNotifications = $notificationPreview->whereNull('read_at')->count() + (auth()->check() && auth()->user()->hasPermission('notifications.view_own') ? auth()->user()->horusNotifications()->where('in_app_visible', true)->unread()->whereNotIn('id', $notificationPreview->pluck('id'))->count() : 0))
    <title>@yield('title', 'Dashboard') · {{ $brandIdentity->name }}</title>
    <x-brand.favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @if(! $isHorusWorkspace && $brand?->primary_color)style="--hm-tenant-accent: {{ $brand->primary_color }}"@endif>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="admin-shell">
        <aside class="sidebar" id="control-navigation" aria-label="Primary navigation">
            <x-brand.product-lockup context="workspace" variant="emblem" :href="url('/')" class="sidebar-brand" />
            <p class="eyebrow">{{ $workspaceLabel }}</p>
            @if(auth()->check())
                <div class="sidebar-quick-links" aria-label="Quick access">
                    @if($brand?->type === \App\Enums\OrganizationType::Publisher)
                        @if(auth()->user()->hasPermission('finance.publisher.view_own'))<a href="{{ route('publisher.finance.overview') }}">Reports &amp; earnings</a>@endif
                        @if(auth()->user()->hasPermission('sites.view'))<a href="{{ route('publisher.sites.index') }}">My websites</a>@endif
                        @if(auth()->user()->hasPermission('support.tickets.view_own'))<a href="{{ route('support.tickets.index') }}">Get support</a>@endif
                    @elseif($brand?->type === \App\Enums\OrganizationType::HorusMedia)
                        @if(auth()->user()->hasPermission('publishers.view'))<a href="{{ route('admin.publishers.index') }}">Publishers</a>@endif
                        @if(auth()->user()->hasPermission('sites.view'))<a href="{{ route('admin.sites.index') }}">Websites</a>@endif
                        @if(auth()->user()->hasPermission('demand.manage'))<a href="{{ route('admin.demand.quick.create') }}">Quick Monetize</a>@endif
                    @endif
                </div>
            @endif
            <x-control-plane.navigation :groups="$navigationGroups" />
            <div class="sidebar-account">
                <span>{{ auth()->user()->name }}</span>
                <small>{{ auth()->user()->email }}</small>
                <a class="text-link" href="{{ route('account.index') }}">Account &amp; Security</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">Sign out</button></form>
            </div>
        </aside>
        <button class="sidebar-scrim" type="button" data-nav-close aria-label="Close navigation"></button>
        <main id="main-content" tabindex="-1">
            <header class="topbar">
                <button class="mobile-nav-toggle" type="button" data-nav-toggle aria-controls="control-navigation" aria-expanded="false"><span aria-hidden="true">☰</span><span class="sr-only">Open navigation</span></button>
                <x-brand.product-lockup context="workspace" variant="header" :href="url('/')" :compact="true" class="mobile-product-lockup" />
                <div class="topbar-title">
                    <p class="eyebrow"><a class="topbar-home-link" href="{{ route('dashboard') }}">Home</a> <span aria-hidden="true">/</span> {{ $workspaceLabel }}</p>
                    <h1>@yield('heading', 'Dashboard')</h1>
                </div>
                @if(auth()->user()->hasPermission('notifications.view_own'))
                <details class="notification-bell"><summary aria-label="Notifications">🔔 @if($unreadNotifications)<span aria-label="{{ $unreadNotifications }} unread notifications">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>@endif</summary><div class="notification-popover"><strong>Latest notifications</strong>@forelse($notificationPreview as $item)<a href="{{ route('notifications.index') }}"><span>{{ $item->title }}</span><small>{{ $item->created_at->diffForHumans() }}</small></a>@empty<p class="muted">No notifications yet.</p>@endforelse<a class="section-anchor" href="{{ route('notifications.index') }}">Open Notification Center</a></div></details>
                @endif
                <span class="status">{{ $workspaceLabel }}</span>
            </header>
            @if(session()->has('impersonator_id'))
                <form method="POST" action="{{ route('admin.impersonate.stop') }}" class="impersonation-banner">@csrf @method('DELETE') <span>Impersonating {{ auth()->user()->email }}</span> <button type="submit">Stop impersonation</button></form>
            @endif
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if(session('error'))<div class="notice error" role="alert">{{ session('error') }}</div>@endif
            @if($errors->any())<div class="notice error validation-summary" role="alert" tabindex="-1"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
</body>
</html>
