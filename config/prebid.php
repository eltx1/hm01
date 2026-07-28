<?php

return [
    'version' => env('PREBID_VERSION', '11.26.0'),
    'source_repository' => env('PREBID_SOURCE_REPOSITORY', 'https://github.com/prebid/Prebid.js.git'),
    'cdn_url' => rtrim(env('HORUS_CDN_URL', 'https://cdn.horusmedia.net') ?: 'https://cdn.horusmedia.net', '/'),
    'asset_directory' => 'assets/prebid',
    'default_timeout_ms' => (int) env('PREBID_DEFAULT_TIMEOUT_MS', 1200),
    'confirmation_phrase' => env('PREBID_GAM_CONFIRMATION_PHRASE', 'CREATE PREBID OBJECTS'),
    'default_currency' => env('PREBID_DEFAULT_CURRENCY', 'USD'),
    'default_creative_sizes' => [[1, 1]],
    'universal_creative_url' => env('PREBID_UNIVERSAL_CREATIVE_URL', 'https://cdn.jsdelivr.net/npm/prebid-universal-creative@1.17.2/dist/creative.js'),
    'modules' => [
        'pubmaticBidAdapter',
        'rubiconBidAdapter',
        'openxBidAdapter',
        'consentManagementTcf',
        'consentManagementGpp',
        'currency',
        'gptPreAuction',
        'priceFloors',
    ],
];
