<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>@yield('title', 'Publisher Application') · Horus Media</title>
    <x-brand.favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="applicant-shell">
        <header class="applicant-topbar">
            <div><x-brand.product-lockup context="horus" variant="header" :href="route('publisher-application.show')" class="applicant-brand" /><p class="eyebrow">Publisher Application</p></div>
            <div>@if(config('publisher-applications.support_url'))<a class="text-link" href="{{ config('publisher-applications.support_url') }}">Need help?</a>@endif<span>{{ auth()->user()->email }}</span><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">Sign out</button></form></div>
        </header>
        <main class="applicant-content" id="main-content" tabindex="-1">
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if(session('error'))<div class="notice error" role="alert">{{ session('error') }}</div>@endif
            @if($errors->any())<div class="notice error validation-summary" role="alert" tabindex="-1"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
</body>
</html>
