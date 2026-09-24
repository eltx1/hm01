<!DOCTYPE html>
<html data-hm-theme="dark" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    @php($brand = auth()->user()?->organization)
    @php($brandIdentity = app(\App\Support\Branding\BrandIdentityResolver::class)->forWorkspace(auth()->user()))
    @php($isHorusWorkspace = $brand?->type === \App\Enums\OrganizationType::HorusMedia)
    @php($navigationGroups = auth()->check() ? app(\App\Services\ControlPlane\ControlPlaneNavigation::class)->for(auth()->user()) : [])
    @php($workspaceLabel = match ($brand?->type) {
        \App\Enums\OrganizationType::Publisher => 'Publisher workspace',
        \App\Enums\OrganizationType::Advertiser => 'Advertiser workspace',
        \App\Enums\OrganizationType::Partner => 'Partner workspace',
        default => 'Admin workspace',
    })
    @php($activeNavigationGroup = collect($navigationGroups)->first(fn ($group) => collect($group['items'])->contains(fn ($item) => collect($item['active'])->contains(fn ($pattern) => request()->routeIs($pattern)))))
    @php($activeNavigationItem = collect($activeNavigationGroup['items'] ?? [])->first(fn ($item) => collect($item['active'])->contains(fn ($pattern) => request()->routeIs($pattern))))
    @php($notificationPreview = auth()->check() && auth()->user()->hasPermission('notifications.view_own') ? auth()->user()->horusNotifications()->where('in_app_visible', true)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get() : collect())
    @php($unreadNotifications = $notificationPreview->whereNull('read_at')->count() + (auth()->check() && auth()->user()->hasPermission('notifications.view_own') ? auth()->user()->horusNotifications()->where('in_app_visible', true)->unread()->whereNotIn('id', $notificationPreview->pluck('id'))->count() : 0))
    <title>@yield('title', 'Dashboard') · {{ $brandIdentity->name }}</title>
    <x-brand.favicons />
    <script src="{{ asset('assets/dashboard-theme.js') }}?v={{ substr(hash_file('sha256', public_path('assets/dashboard-theme.js')), 0, 12) }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @if(! $isHorusWorkspace && $brand?->primary_color)style="--hm-tenant-accent: {{ $brand->primary_color }}"@endif>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="admin-shell">
        <aside class="sidebar" id="control-navigation" aria-label="Primary navigation">
            <x-brand.product-lockup context="workspace" variant="emblem" :href="url('/')" class="sidebar-brand" />
            <p class="eyebrow">{{ $workspaceLabel }}</p>
            <label class="navigation-finder">
                <span class="sr-only">Find a page</span>
                <input type="search" placeholder="Find a page…" autocomplete="off" data-nav-filter aria-label="Find a page in navigation">
            </label>
            <p class="navigation-empty muted" data-nav-empty hidden>No matching page.</p>
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
                    <p class="eyebrow workspace-context">
                        <span>{{ $workspaceLabel }}</span>
                        @if($activeNavigationGroup)<span aria-hidden="true">/</span><span>{{ $activeNavigationGroup['label'] }}</span>@endif
                        @if($activeNavigationItem)<span aria-hidden="true">/</span><span>{{ $activeNavigationItem['label'] }}</span>@endif
                    </p>
                    <h1>@yield('heading', 'Dashboard')</h1>
                </div>
                <button type="button" class="hm-button-secondary theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch to White Mode" hidden>White Mode</button>
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
