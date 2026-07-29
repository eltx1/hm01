<?php

return [
    'cdn_url' => rtrim(env('HORUS_PREBID_CDN_URL', env('HORUS_CDN_URL', 'https://cdn.horusmedia.net')), '/'),
    'version' => env('HORUS_PREBID_VERSION', '11.14.0'),
    'build_path' => env('HORUS_PREBID_BUILD_PATH', 'assets/prebid/horus-prebid.min.js'),
    'source_path' => env('HORUS_PREBID_SOURCE_PATH', 'assets/prebid/horus-prebid.js'),
    'download_url' => env('HORUS_PREBID_DOWNLOAD_URL', 'https://js-download.prebid.org/download'),
    'default_timeout_ms' => (int) env('HORUS_PREBID_TIMEOUT_MS', 1200),
    'default_currency' => env('HORUS_PREBID_CURRENCY', 'USD'),
];
