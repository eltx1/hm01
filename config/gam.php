<?php

return [
    'application_name' => env('GAM_APPLICATION_NAME', 'Horus Media Platform'),
    'rest' => [
        'base_url' => env('GAM_REST_BASE_URL', 'https://admanager.googleapis.com/v1'),
        'timeout' => (int) env('GAM_REST_TIMEOUT', 30),
    ],
    'oauth' => [
        'scope' => 'https://www.googleapis.com/auth/admanager',
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ],
    'retry' => [
        'read_attempts' => (int) env('GAM_READ_ATTEMPTS', 3),
        'write_attempts' => (int) env('GAM_SAFE_WRITE_ATTEMPTS', 2),
        'base_delay_ms' => (int) env('GAM_RETRY_BASE_DELAY_MS', 250),
    ],
    'dry_run_default' => env('GAM_DRY_RUN_DEFAULT', true),
    'credential_reference_prefixes' => ['env:', 'file:'],
    'required_permissions' => ['api.access', 'network.read'],
];
