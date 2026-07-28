<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · Horus Media</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
    <main class="auth-card hm-panel">
        <a class="auth-brand" href="{{ route('login') }}">Horus Media</a>
        <p class="eyebrow">White Label Ad Network</p>
        @if (session('status')) <div class="notice">{{ session('status') }}</div> @endif
        @yield('content')
    </main>
</body>
</html>
