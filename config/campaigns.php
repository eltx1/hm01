<?php

return [
    // Direct Advertiser campaigns already existed before Task 41. Keep that deployed
    // product state by default; the typed Global Setting can turn the pilot off at
    // runtime without making an environment variable the control plane.
    'advertiser_campaigns_enabled' => env('ADVERTISER_CAMPAIGNS_ENABLED', true),
    'creative_disk' => env('CAMPAIGN_CREATIVE_DISK', 'local'),
    'creative_directory' => env('CAMPAIGN_CREATIVE_DIRECTORY', 'campaign-creatives'),
    'max_file_bytes' => [
        'IMAGE' => 10 * 1024 * 1024,
        'HTML5' => 20 * 1024 * 1024,
        'HOUSE' => 20 * 1024 * 1024,
    ],
    'allowed_extensions' => [
        'IMAGE' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'HTML5' => ['zip'],
        'HOUSE' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip'],
    ],
    'allowed_mime_types' => [
        'IMAGE' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'HTML5' => ['application/zip', 'application/x-zip-compressed'],
        'HOUSE' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/zip', 'application/x-zip-compressed'],
    ],
    'html5_archive' => [
        'max_files' => (int) env('HTML5_MAX_FILES', 250),
        'max_uncompressed_bytes' => (int) env('HTML5_MAX_UNCOMPRESSED_BYTES', 50 * 1024 * 1024),
        'max_compression_ratio' => (float) env('HTML5_MAX_COMPRESSION_RATIO', 100),
    ],
    'malware_scanner_binary' => env('MALWARE_SCANNER_BINARY'),
    'malware_scan_timeout' => (int) env('MALWARE_SCAN_TIMEOUT', 30),
    'malware_scan_fail_closed' => env('MALWARE_SCAN_FAIL_CLOSED', true),
    'invoice_tax_basis_points' => (int) env('ADVERTISER_INVOICE_TAX_BPS', 0),
];
