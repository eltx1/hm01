<?php

return [
    'default_provider' => env('THOTH_PROVIDER', 'OPENAI'),
    'default_models' => ['OPENAI' => 'gpt-5-mini', 'GEMINI' => 'gemini-2.5-flash'],
    'models' => [
        'OPENAI' => ['gpt-5-mini', 'gpt-4.1-mini'],
        'GEMINI' => ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro'],
    ],
    'credentials' => [
        'OPENAI' => env('THOTH_OPENAI_API_KEY'),
        'GEMINI' => env('THOTH_GEMINI_API_KEY'),
    ],
    'timeout_seconds' => (int) env('THOTH_TIMEOUT_SECONDS', 20),
    'max_output_tokens' => (int) env('THOTH_MAX_OUTPUT_TOKENS', 1800),
    'connection_max_age_minutes' => (int) env('THOTH_CONNECTION_MAX_AGE_MINUTES', 1440),
    'application_domain_verification_fresh_days' => (int) env('THOTH_APPLICATION_DOMAIN_VERIFICATION_FRESH_DAYS', 7),
    'evidence' => [
        'max_pages' => 4,
        'max_redirects' => 3,
        'max_bytes_per_page' => 262144,
        'max_text_chars' => 30000,
        'max_total_text_chars' => 60000,
        'timeout_seconds' => 8,
        'connect_timeout_seconds' => 4,
    ],
    'policy_version' => 'thoth-quality-v2',
    'schema_version' => '1.0.0',
];
