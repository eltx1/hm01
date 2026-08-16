<?php

return [
    'connect_timeout_seconds' => (int) env('ADS_TXT_CONNECT_TIMEOUT', 3),
    'timeout_seconds' => (int) env('ADS_TXT_TIMEOUT', 8),
    'max_response_bytes' => (int) env('ADS_TXT_MAX_RESPONSE_BYTES', 1_048_576),
    'max_redirects' => (int) env('ADS_TXT_MAX_REDIRECTS', 3),
    'fresh_for_days' => (int) env('ADS_TXT_FRESH_FOR_DAYS', 7),
    'history_snapshots' => (int) env('ADS_TXT_HISTORY_SNAPSHOTS', 30),
    'user_agent' => env('ADS_TXT_USER_AGENT', 'HorusMedia-AdsTxt-Compliance/1.1 (+https://horusmedia.net)'),

    // Optional official ads.txt directives are explicit only. Nothing is inferred.
    'contact' => env('ADS_TXT_CONTACT'),
    'contact_reviewed' => (bool) env('ADS_TXT_CONTACT_REVIEWED', false),
    'inventory_partner_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADS_TXT_INVENTORY_PARTNER_DOMAINS', ''))))),
    'subdomains' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADS_TXT_SUBDOMAINS', ''))))),
];
