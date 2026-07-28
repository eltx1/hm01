<?php

return [
    'api_version' => env('GAM_API_VERSION', 'v202602'),
    'application_name' => env('GAM_APPLICATION_NAME', 'Horus Media Platform'),
    'soap' => [
        'base_url' => env('GAM_SOAP_BASE_URL', 'https://ads.google.com/apis/ads/publisher'),
        'connect_timeout' => (int) env('GAM_SOAP_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('GAM_SOAP_TIMEOUT', 30),
        'wsdl_cache' => env('GAM_SOAP_WSDL_CACHE', true),
    ],
    'oauth' => [
        'scope' => 'https://www.googleapis.com/auth/dfp',
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
