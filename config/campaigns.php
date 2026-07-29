<?php

return [
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
    'invoice_tax_basis_points' => (int) env('ADVERTISER_INVOICE_TAX_BPS', 0),
];
