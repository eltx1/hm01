<?php

return [
    'public_registration_enabled' => (bool) env('PUBLIC_PUBLISHER_REGISTRATION_ENABLED', false),

    'turnstile' => [
        'enabled' => (bool) env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'expected_hostname' => env('TURNSTILE_EXPECTED_HOSTNAME', parse_url((string) env('APP_URL', ''), PHP_URL_HOST)),
        'action' => env('TURNSTILE_ACTION', 'publisher_registration'),
        'provider' => env('TURNSTILE_PROVIDER', 'cloudflare'),
        'timeout_seconds' => (int) env('TURNSTILE_TIMEOUT_SECONDS', 5),
        'test_token' => env('TURNSTILE_TEST_TOKEN', 'turnstile-test-valid'),
    ],

    'legal_documents' => [
        'TERMS_OF_SERVICE' => [
            'label' => 'Terms of Service',
            'version' => env('PUBLISHER_TERMS_OF_SERVICE_VERSION'),
            'url' => env('PUBLISHER_TERMS_OF_SERVICE_URL'),
            'required' => (bool) env('PUBLISHER_TERMS_OF_SERVICE_REQUIRED', true),
        ],
        'PRIVACY_POLICY' => [
            'label' => 'Privacy Policy',
            'version' => env('PUBLISHER_PRIVACY_POLICY_VERSION'),
            'url' => env('PUBLISHER_PRIVACY_POLICY_URL'),
            'required' => (bool) env('PUBLISHER_PRIVACY_POLICY_REQUIRED', true),
        ],
        'PUBLISHER_TERMS' => [
            'label' => 'Publisher Terms',
            'version' => env('PUBLISHER_TERMS_VERSION'),
            'url' => env('PUBLISHER_TERMS_URL'),
            'required' => (bool) env('PUBLISHER_TERMS_REQUIRED', true),
        ],
    ],

    'support_url' => env('HORUS_PUBLIC_SUPPORT_URL', env('SUPPLY_CHAIN_CONTACT_EMAIL') ? 'mailto:'.env('SUPPLY_CHAIN_CONTACT_EMAIL') : null),
];
