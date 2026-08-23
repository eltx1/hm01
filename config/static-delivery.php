<?php

return [
    'driver' => env('HORUS_STATIC_DELIVERY_DRIVER', 'local'),
    'local_root' => env('HORUS_STATIC_DELIVERY_LOCAL_ROOT') ?: base_path('cloudflare-pages-dist'),
    'normal_batch_interval_minutes' => (int) env('HORUS_STATIC_DELIVERY_BATCH_INTERVAL_MINUTES', 30),
    'process_lock_seconds' => (int) env('HORUS_STATIC_DELIVERY_PROCESS_LOCK_SECONDS', 180),
    'pending_stale_grace_minutes' => (int) env('HORUS_STATIC_DELIVERY_PENDING_STALE_GRACE_MINUTES', 5),
    'retention_per_environment' => (int) env('HORUS_STATIC_DELIVERY_RETENTION', 5),
    'max_attempts' => (int) env('HORUS_STATIC_DELIVERY_MAX_ATTEMPTS', 5),
    'retry_delay_seconds' => (int) env('HORUS_STATIC_DELIVERY_RETRY_DELAY', 300),
    'monthly_deployment_budget' => (int) env('HORUS_STATIC_DELIVERY_MONTHLY_BUDGET', 450),
    'emergency_reserve' => (int) env('HORUS_STATIC_DELIVERY_EMERGENCY_RESERVE', 25),
    'budget_warning_threshold' => (int) env('HORUS_STATIC_DELIVERY_BUDGET_WARNING', 400),
    'file_budget' => [
        'warning_threshold' => (int) env('HORUS_STATIC_DELIVERY_FILE_WARNING', 18000),
        'hard_limit' => (int) env('HORUS_STATIC_DELIVERY_FILE_LIMIT', 20000),
        'max_file_bytes' => (int) env('HORUS_STATIC_DELIVERY_MAX_FILE_BYTES', 26214400),
    ],
    'cloudflare' => [
        'github_repository' => env('HORUS_EDGE_GITHUB_REPOSITORY', 'eltx1/hm01'),
        'delivery_branch' => env('HORUS_EDGE_GITHUB_BRANCH', 'edge-delivery'),
        'github_token_reference' => env('HORUS_EDGE_GITHUB_TOKEN_REFERENCE'),
        'dry_run' => (bool) env('HORUS_STATIC_DELIVERY_DRY_RUN', false),
        'connect_timeout' => (int) env('HORUS_EDGE_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('HORUS_EDGE_REQUEST_TIMEOUT', 20),
        'read_attempts' => (int) env('HORUS_EDGE_READ_ATTEMPTS', 3),
    ],
    'external_sync' => [
        'manifest_url' => rtrim(env('HORUS_STATIC_DELIVERY_MANIFEST_URL', env('HORUS_CDN_URL', 'https://cdn.horusmedia.net')), '/').'/delivery-manifest.json',
        'confirmation_path' => storage_path('app/static-delivery/confirmed-manifest'),
        'connect_timeout' => (int) env('HORUS_EDGE_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('HORUS_EDGE_REQUEST_TIMEOUT', 20),
    ],
];
