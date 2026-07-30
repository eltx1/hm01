<?php

return [
    'heartbeat_stale_after_seconds' => (int) env('HEARTBEAT_STALE_AFTER_SECONDS', 180),
    'queue_max_time' => (int) env('QUEUE_CRON_MAX_TIME', 50),
    'queue_tries' => (int) env('QUEUE_CRON_TRIES', 3),
    'error_notification_email' => env('ERROR_NOTIFICATION_EMAIL'),
    'controls' => [
        'PLATFORM' => ['AD_SERVING', 'PREBID', 'NATIVE_DEMAND'],
        'SITE' => ['AD_SERVING', 'PREBID', 'NATIVE_DEMAND'],
        'PLACEMENT' => ['AD_SERVING'],
        'GAM_CONNECTION' => ['AD_SERVING'],
        'DEMAND_NETWORK' => ['AD_SERVING'],
    ],
];
