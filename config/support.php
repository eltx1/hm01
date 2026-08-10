<?php

return [
    'attachment_max_bytes' => (int) env('SUPPORT_ATTACHMENT_MAX_BYTES', 10 * 1024 * 1024),
    'attachment_mimes' => [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
    ],
    'sla' => [
        'LOW' => ['name' => 'Low priority', 'first_response_minutes' => 1440, 'resolution_minutes' => 10080, 'warning_before_minutes' => 120],
        'NORMAL' => ['name' => 'Standard support', 'first_response_minutes' => 480, 'resolution_minutes' => 4320, 'warning_before_minutes' => 60],
        'HIGH' => ['name' => 'High priority', 'first_response_minutes' => 120, 'resolution_minutes' => 1440, 'warning_before_minutes' => 30],
        'URGENT' => ['name' => 'Horus urgent', 'first_response_minutes' => 30, 'resolution_minutes' => 480, 'warning_before_minutes' => 10],
    ],
];
