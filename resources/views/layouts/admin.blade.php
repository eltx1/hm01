<!DOCTYPE html>
<html data-hm-theme="dark" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
<body @class(['hm-whatsapp-enabled' => $brand?->type === \App\Enums\OrganizationType::Publisher]) @if(! $isHorusWorkspace && $brand?->primary_color)style="--hm-tenant-accent: {{ $brand->primary_color }}"@endif>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="admin-shell">
        <aside class="sidebar" id="control-navigation" aria-label="Primary navigation">
            <div class="sidebar-header">
                <x-brand.product-lockup context="workspace" variant="emblem" :href="url('/')" class="sidebar-brand" />
                <button class="sidebar-close" type="button" data-nav-close aria-label="Close navigation"><x-ui-icon name="close" /></button>
            </div>
            <div class="sidebar-scroll">
                <p class="eyebrow sidebar-workspace">{{ $workspaceLabel }}</p>
                <label class="navigation-finder">
                    <span class="sr-only">Find a page</span>
                    <x-ui-icon name="search" class="navigation-finder-icon" />
                    <input type="search" placeholder="Find a page…" autocomplete="off" data-nav-filter aria-label="Find a page in navigation">
                </label>
                <p class="navigation-empty muted" data-nav-empty hidden>No matching page.</p>
                <x-control-plane.navigation :groups="$navigationGroups" />
            </div>
            @php($accountInitials = collect(preg_split('/\s+/u', trim((string) auth()->user()->name)) ?: [])->filter()->reject(fn ($word) => str_ends_with($word, '.'))->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') ?: 'H')
            <div class="sidebar-account">
                <div class="sidebar-account-identity">
                    <span class="account-avatar" aria-hidden="true">{{ $accountInitials }}</span>
                    <span class="sidebar-account-copy"><span>{{ auth()->user()->name }}</span><small>{{ auth()->user()->email }}</small></span>
                </div>
                <div class="sidebar-account-actions">
                    <a class="text-link" href="{{ route('account.index') }}"><x-ui-icon name="user" /><span>My account</span></a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit"><x-ui-icon name="logout" /><span>Sign out</span></button></form>
                </div>
            </div>
        </aside>
        <button class="sidebar-scrim" tabindex="-1" type="button" data-nav-close aria-label="Close navigation"></button>
        <main id="main-content" tabindex="-1">
            <header class="topbar">
                <button class="mobile-nav-toggle" type="button" data-nav-toggle aria-controls="control-navigation" aria-expanded="false"><x-ui-icon name="menu" /><span class="sr-only">Open navigation</span></button>
                <x-brand.product-lockup context="workspace" variant="header" :href="url('/')" :compact="true" class="mobile-product-lockup" />
                <nav class="workspace-context" aria-label="Breadcrumb">
                    <span>{{ $workspaceLabel }}</span>
                    @if($activeNavigationGroup)<x-ui-icon name="chevron-right" class="workspace-context-separator" /><span>{{ $activeNavigationGroup['label'] }}</span>@endif
                    @if($activeNavigationItem)<x-ui-icon name="chevron-right" class="workspace-context-separator" /><span aria-current="page">{{ $activeNavigationItem['label'] }}</span>@endif
                </nav>
                <div class="topbar-actions">
                    <button type="button" class="topbar-icon-button theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch to White Mode" title="Switch to White Mode" hidden><x-ui-icon name="sun" class="theme-icon-to-light" /><x-ui-icon name="moon" class="theme-icon-to-dark" /><span class="sr-only" data-theme-label>White Mode</span></button>
                    @if(auth()->user()->hasPermission('notifications.view_own'))
                    <details class="notification-bell">
                        <summary class="topbar-icon-button" aria-label="Notifications" title="Notifications"><x-ui-icon name="bell" />@if($unreadNotifications)<span class="notification-count" aria-label="{{ $unreadNotifications }} unread notifications">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>@endif</summary>
                        <div class="notification-popover">
                            <div class="notification-popover-header"><strong>Latest notifications</strong>@if($unreadNotifications)<span class="status-badge status-badge-warning">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }} unread</span>@endif</div>
                            @forelse($notificationPreview as $item)<a class="notification-preview-item" href="{{ route('notifications.index') }}"><span>{{ $item->title }}</span><small>{{ $item->created_at->diffForHumans() }}</small></a>@empty<p class="muted notification-preview-empty"><x-ui-icon name="bell" />No notifications yet.</p>@endforelse
                            <a class="section-anchor" href="{{ route('notifications.index') }}">Open Notification Center <x-ui-icon name="arrow-right" /></a>
                        </div>
                    </details>
                    @endif
                </div>
            </header>
            <div class="page-heading">
                <h1>@yield('heading', 'Dashboard')</h1>
            </div>
            <x-impersonation-banner />
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}@if(session('operation_reference'))<small>Operation reference: {{ str(session('operation_reference'))->limit(80) }}</small>@endif</div>@endif
            @if(session('error'))<div class="notice error" role="alert">{{ session('error') }}</div>@endif
            @if($errors->any())<div class="notice error validation-summary" role="alert" tabindex="-1"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
    @if($brand?->type === \App\Enums\OrganizationType::Publisher)
        <x-whatsapp-contact />
    @endif
</body>
</html>
