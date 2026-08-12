<?php

return [
    'heartbeat_stale_after_seconds' => (int) env('HEARTBEAT_STALE_AFTER_SECONDS', 180),
    'queue_max_time' => (int) env('QUEUE_CRON_MAX_TIME', 50),
    'queue_tries' => (int) env('QUEUE_CRON_TRIES', 3),
    'error_notification_email' => env('ERROR_NOTIFICATION_EMAIL'),
    'controls' => [
        'PLATFORM' => ['AD_SERVING', 'GAM', 'PREBID', 'DIRECT_JS', 'NATIVE_DEMAND'],
        'SITE' => ['AD_SERVING', 'GAM', 'PREBID', 'DIRECT_JS', 'NATIVE_DEMAND'],
        'PLACEMENT' => ['AD_SERVING', 'PREBID', 'DIRECT_JS'],
        'GAM_CONNECTION' => ['AD_SERVING', 'GAM'],
        'DEMAND_NETWORK' => ['AD_SERVING', 'DIRECT_JS', 'NATIVE_DEMAND'],
    ],
];
