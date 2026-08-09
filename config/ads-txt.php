<?php

return [
    'connect_timeout_seconds' => (int) env('ADS_TXT_CONNECT_TIMEOUT', 3),
    'timeout_seconds' => (int) env('ADS_TXT_TIMEOUT', 8),
    'max_response_bytes' => (int) env('ADS_TXT_MAX_RESPONSE_BYTES', 1_048_576),
    'max_redirects' => (int) env('ADS_TXT_MAX_REDIRECTS', 3),
    'fresh_for_days' => (int) env('ADS_TXT_FRESH_FOR_DAYS', 7),
    'history_snapshots' => (int) env('ADS_TXT_HISTORY_SNAPSHOTS', 30),
    'user_agent' => env('ADS_TXT_USER_AGENT', 'HorusMedia-AdsTxt-Compliance/1.1 (+https://horusmedia.net)'),
];
