<?php

return [
    'headers' => [
        'content_security_policy' => env('SECURITY_CSP', "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data: https:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; upgrade-insecure-requests"),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=(), payment=()'),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'hsts_include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'hsts_preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],
    'authentication' => [
        'max_failed_attempts' => (int) env('AUTH_MAX_FAILED_ATTEMPTS', 8),
        'lock_minutes' => (int) env('AUTH_LOCK_MINUTES', 30),
        // Owner policy: simple account activation and password authentication only.
        // Keep the implementation available for tests/future migrations, but production
        // environment values must not silently re-enable either workflow.
        'email_verification_required' => app()->environment('production')
            ? false
            : (bool) env('AUTH_EMAIL_VERIFICATION_REQUIRED', true),
        'administrator_2fa_required' => app()->environment('production')
            ? false
            : (bool) env('AUTH_ADMIN_2FA_REQUIRED', true),
    ],
    'uploads' => [
        'contract_max_bytes' => (int) env('UPLOAD_CONTRACT_MAX_BYTES', 10485760),
        'invoice_max_bytes' => (int) env('UPLOAD_INVOICE_MAX_BYTES', 10485760),
    ],
];
