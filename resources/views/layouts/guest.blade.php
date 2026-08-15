<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · Horus Media</title>
    <x-brand.favicons />
    @vite(['resources/css/app.css', 'resources/css/publisher-application.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
    <div class="auth-shell">
        <aside class="auth-context" aria-label="Horus Media product identity">
            <div class="auth-context-content">
                <x-brand.emblem class="auth-context-emblem" />
                <p class="eyebrow">Premium programmatic media marketplace</p>
                <h2>See the market.<span>Shape the outcome.</span></h2>
                <p class="auth-context-lead">One secure workspace for publisher monetization, advertiser operations, reporting, privacy readiness, and controlled delivery.</p>
                <ul class="auth-context-points" aria-label="Control Plane capabilities">
                    <li><strong>Demand</strong>Campaign and marketplace operations</li>
                    <li><strong>Supply</strong>Publisher yield and serving controls</li>
                    <li><strong>Intelligence</strong>Evidence-backed operational decisions</li>
                </ul>
            </div>
        </aside>
        <div class="auth-workspace">
            <main class="auth-card hm-panel">
                <a class="auth-card-brand" href="{{ route('login') }}" aria-label="Horus Media sign in"><x-brand.full-logo /></a>
                <p class="eyebrow">Advertising Control Plane</p>
                @if (session('status')) <div class="notice" role="status">{{ session('status') }}</div> @endif
                @yield('content')
            </main>
            <p class="auth-product-note">Horus Media · Advertising, monetization, and intelligent programmatic growth.@if(config('publisher-applications.support_url')) <a class="text-link" href="{{ config('publisher-applications.support_url') }}">Need help?</a>@endif</p>
        </div>
    </div>
</body>
</html>
