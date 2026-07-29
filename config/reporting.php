<?php

return [
    'default_currency' => env('REPORTING_DEFAULT_CURRENCY', 'USD'),
    'default_timezone' => env('REPORTING_DEFAULT_TIMEZONE', 'UTC'),
    'default_publisher_share_bp' => (int) env('REPORTING_DEFAULT_PUBLISHER_SHARE_BP', 7000),
    'default_horus_share_bp' => (int) env('REPORTING_DEFAULT_HORUS_SHARE_BP', 3000),
    'default_mcm_share_bp' => (int) env('REPORTING_DEFAULT_MCM_SHARE_BP', 0),
    'discrepancy_warning_bp' => (int) env('REPORTING_DISCREPANCY_WARNING_BP', 100),
    'csv_max_bytes' => (int) env('REPORTING_CSV_MAX_BYTES', 25 * 1024 * 1024),
    'retry_delay_minutes' => (int) env('REPORTING_RETRY_DELAY_MINUTES', 30),
    'hourly_lookback_hours' => (int) env('REPORTING_HOURLY_LOOKBACK_HOURS', 3),
    'daily_lookback_days' => (int) env('REPORTING_DAILY_LOOKBACK_DAYS', 2),
    'statement_invoice_max_bytes' => (int) env('REPORTING_STATEMENT_INVOICE_MAX_BYTES', 10 * 1024 * 1024),
    'sources' => [
        'HORUS_GAM' => ['name' => 'Horus GAM', 'primary' => true, 'capabilities' => ['API', 'HOURLY', 'DAILY', 'MONTHLY']],
        'MCM_PARTNER_GAM' => ['name' => 'MCM Partner GAM', 'capabilities' => ['API', 'HOURLY', 'DAILY', 'MONTHLY']],
        'PUBLISHER_GAM' => ['name' => 'Publisher GAM', 'capabilities' => ['API', 'HOURLY', 'DAILY', 'MONTHLY']],
        'PREBID_ESTIMATES' => ['name' => 'Prebid Estimates', 'capabilities' => ['ESTIMATED', 'CSV', 'MANUAL']],
        'MGID' => ['name' => 'MGID', 'capabilities' => ['API', 'CSV']],
        'TABOOLA' => ['name' => 'Taboola', 'capabilities' => ['API', 'CSV']],
        'SPEAKOL' => ['name' => 'Speakol', 'capabilities' => ['API', 'CSV']],
        'OUTBRAIN' => ['name' => 'Outbrain', 'capabilities' => ['API', 'CSV']],
        'CUSTOM_CSV' => ['name' => 'Custom CSV', 'capabilities' => ['CSV']],
        'MANUAL_ADJUSTMENT' => ['name' => 'Manual Adjustment', 'capabilities' => ['MANUAL']],
    ],
];
