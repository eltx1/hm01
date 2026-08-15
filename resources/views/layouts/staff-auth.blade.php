<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · Horus Media Staff</title>
    <x-brand.favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page staff-auth-page">
    <div class="auth-shell">
        <aside class="auth-context" aria-label="Horus Media staff identity">
            <div class="auth-context-content">
                <x-brand.emblem class="auth-context-emblem" />
                <p class="eyebrow">Horus Media · Staff Control Plane</p>
                <h2>Secure staff access.<span>Operational authority stays enforced.</span></h2>
                <p class="auth-context-lead">This entry point is reserved for eligible Horus Media staff. Authentication, Horus organization membership, RBAC, two-factor authentication, middleware, and audit remain the enforceable security boundaries.</p>
                <ul class="auth-context-points" aria-label="Staff access protections">
                    <li><strong>Identity</strong>Canonical Horus Media account credentials</li>
                    <li><strong>Second factor</strong>TOTP required before operational access</li>
                    <li><strong>Audit</strong>Staff authentication activity is recorded</li>
                </ul>
            </div>
        </aside>
        <div class="auth-workspace">
            <main class="auth-card hm-panel">
                <a class="auth-card-brand" href="{{ route('admin.login') }}" aria-label="Horus Media staff sign in"><x-brand.full-logo /></a>
                <p class="eyebrow">Secure Staff Access</p>
                @if (session('status')) <div class="notice" role="status">{{ session('status') }}</div> @endif
                @yield('content')
            </main>
            <p class="auth-product-note">Authorized Horus Media staff only. Customer accounts should use the standard portal.</p>
        </div>
    </div>
</body>
</html>
