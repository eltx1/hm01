<?php

return [
    'provider' => 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED',
    'origin' => env('HORUS_TRAFFIC_GATE_ORIGIN', 'https://verify.horusmedia.net'),
    'enabled' => false,
    'site_key' => env('HORUS_TRAFFIC_GATE_SITE_KEY'),
    'policy' => 'BALANCED',
    'initial_wait_ms' => 1500,
    'max_wait_ms' => 10000,
    'retry_interval_ms' => 1500,
    'activity_recovery_enabled' => true,
    'bounds' => [
        'initial_wait_ms' => [500, 5000],
        'max_wait_ms' => [2000, 15000],
        'retry_interval_ms' => [500, 10000],
    ],
];
