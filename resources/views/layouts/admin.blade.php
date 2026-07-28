<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($brand = auth()->user()?->organization)
    <title>@yield('title', 'Dashboard') · {{ $brand?->dashboard_title ?: $brand?->name ?: 'Horus Media' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @if($brand?->primary_color)style="--hm-tenant-accent: {{ $brand->primary_color }}"@endif>
    <div class="admin-shell">
        <aside class="sidebar" aria-label="Primary navigation">
            <a class="brand" href="/">@if($brand?->logo_path)<img class="brand-logo" src="{{ Storage::disk('public')->url($brand->logo_path) }}" alt="">@endif{{ $brand?->dashboard_title ?: $brand?->name ?: 'Horus Media' }}</a>
            <p class="eyebrow">Ad Network Control Plane</p>
            <nav>@yield('navigation')</nav>
        </aside>
        <main>
            <header class="topbar">
                <div>
                    <p class="eyebrow">app.horusmedia.net</p>
                    <h1>@yield('heading', 'Dashboard')</h1>
                </div>
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
