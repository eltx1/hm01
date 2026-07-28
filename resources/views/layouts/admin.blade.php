<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · Horus Media</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="admin-shell">
        <aside class="sidebar" aria-label="Primary navigation">
            <a class="brand" href="/">Horus Media</a>
            <p class="eyebrow">Ad Network Control Plane</p>
            <nav>
                <a class="active" href="/">Overview</a>
                <span>Publishers</span>
                <span>Websites</span>
                <span>Placements</span>
                <span>Advertisers & Campaigns</span>
                <span>Reports & Payments</span>
            </nav>
        </aside>
        <main>
            <header class="topbar">
                <div>
                    <p class="eyebrow">app.horusmedia.net</p>
                    <h1>@yield('heading', 'Dashboard')</h1>
                </div>
                <span class="status">Foundation</span>
            </header>
            @yield('content')
        </main>
    </div>
</body>
</html>
