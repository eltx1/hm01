<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>@yield('title', 'Horus Media')</title>
    <x-brand.favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="error-page">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <main class="error-shell hm-panel" id="main-content" tabindex="-1">
        <x-brand.full-logo />
        <p class="error-code" aria-hidden="true">@yield('code')</p>
        <p class="sr-only">Error @yield('code')</p>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <div class="error-actions">
            @auth
                <a class="hm-button-primary" href="{{ route('dashboard') }}">Return to dashboard</a>
            @else
                <a class="hm-button-primary" href="{{ route('login') }}">Sign in</a>
            @endauth
            @if(config('publisher-applications.support_url'))
                <a class="hm-button-secondary" href="{{ config('publisher-applications.support_url') }}">Contact support</a>
            @endif
        </div>
    </main>
</body>
</html>
