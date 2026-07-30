<?php

return [
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_TRUSTED_HOSTS', ''))))),
    'lockout' => ['attempts' => (int) env('AUTH_LOCKOUT_ATTEMPTS', 5), 'minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15)],
    'headers' => [
        'csp' => (bool) env('SECURITY_CSP_ENABLED', true),
        'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
        'csp_report_uri' => env('SECURITY_CSP_REPORT_URI'),
        'hsts' => (bool) env('SECURITY_HSTS_ENABLED', true),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'hsts_include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'hsts_preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],
    'operations_alert_email' => env('OPERATIONS_ALERT_EMAIL'),
];
