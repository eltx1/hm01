<?php

return [
    'default_currency' => env('REPORTING_DEFAULT_CURRENCY', 'USD'),
    // Horus financial reporting is intentionally denominated in USD.
    // GAM network currency remains source metadata; Google performs the
    // conversion in the report query. Do not make this environment-dependent:
    // changing it would mix accounting denominations without an FX ledger.
    'canonical_currency' => 'USD',
    'default_timezone' => env('REPORTING_DEFAULT_TIMEZONE', 'UTC'),
    'default_publisher_share_bp' => (int) env('REPORTING_DEFAULT_PUBLISHER_SHARE_BP', 7000),
    'default_horus_share_bp' => (int) env('REPORTING_DEFAULT_HORUS_SHARE_BP', 3000),
    'default_mcm_share_bp' => (int) env('REPORTING_DEFAULT_MCM_SHARE_BP', 0),
    'discrepancy_warning_bp' => (int) env('REPORTING_DISCREPANCY_WARNING_BP', 100),
    'csv_max_bytes' => (int) env('REPORTING_CSV_MAX_BYTES', 25 * 1024 * 1024),
    'retry_delay_minutes' => (int) env('REPORTING_RETRY_DELAY_MINUTES', 30),
    'hourly_lookback_hours' => (int) env('REPORTING_HOURLY_LOOKBACK_HOURS', 3),
    'daily_lookback_days' => (int) env('REPORTING_DAILY_LOOKBACK_DAYS', 2),
    'financial_source_stale_days' => (int) env('REPORTING_FINANCIAL_SOURCE_STALE_DAYS', 3),
    // Daily drop alerts per website and ad format (Google Ad Exchange evidence, USD).
    'ad_format_alerts' => [
        'min_baseline_days' => (int) env('REPORTING_AD_FORMAT_ALERT_MIN_DAYS', 7),
        'min_baseline_revenue_micros' => (int) env('REPORTING_AD_FORMAT_ALERT_MIN_REVENUE_MICROS', 5_000_000),
        'revenue_drop_ratio' => (float) env('REPORTING_AD_FORMAT_ALERT_REVENUE_RATIO', 0.5),
        'min_baseline_impressions' => (int) env('REPORTING_AD_FORMAT_ALERT_MIN_IMPRESSIONS', 1000),
        'ecpm_drop_ratio' => (float) env('REPORTING_AD_FORMAT_ALERT_ECPM_RATIO', 0.6),
    ],
    // Revenue opportunity suggestions need at least this many peer websites per format.
    'ad_format_opportunities' => ['min_peer_sites' => (int) env('REPORTING_AD_FORMAT_OPPORTUNITY_MIN_PEERS', 3)],
    'statement_invoice_max_bytes' => (int) env('REPORTING_STATEMENT_INVOICE_MAX_BYTES', 10 * 1024 * 1024),
    'sources' => [
        'HORUS_GAM' => ['name' => 'Horus GAM', 'primary' => true, 'capabilities' => ['API', 'HOURLY', 'DAILY', 'MONTHLY'], 'finalized_methods' => ['API']],
        'MCM_PARTNER_GAM' => ['name' => 'MCM Partner GAM', 'capabilities' => ['API', 'HOURLY', 'DAILY', 'MONTHLY'], 'finalized_methods' => ['API']],
        'PUBLISHER_GAM' => ['name' => 'Publisher GAM', 'capabilities' => ['API', 'HOURLY', 'DAILY', 'MONTHLY'], 'finalized_methods' => ['API']],
        'GAM_VIDEO_AD_UNIT' => ['name' => 'GAM Video ad unit', 'capabilities' => ['API', 'DAILY'], 'finalized_methods' => ['API']],
        'GAM_MOBILE_APP' => [
            'name' => 'Mobile app GAM', 'capabilities' => ['API', 'DAILY', 'FINALIZED_API'], 'finalized_methods' => ['API'],
        ],
        'GAM_AD_UNIT' => ['name' => 'GAM ad unit — reporting only', 'capabilities' => ['API', 'DAILY'], 'finalized_methods' => ['API']],
        'PREBID_ESTIMATES' => ['name' => 'Prebid Estimates', 'capabilities' => ['ESTIMATED', 'CSV', 'MANUAL'], 'finalized_methods' => []],
        'MGID' => ['name' => 'MGID', 'capabilities' => ['API', 'CSV', 'MANUAL'], 'finalized_methods' => ['API', 'CSV', 'MANUAL']],
        'TABOOLA' => ['name' => 'Taboola', 'capabilities' => ['API', 'CSV', 'MANUAL'], 'finalized_methods' => ['API', 'CSV', 'MANUAL']],
        'SPEAKOL' => ['name' => 'Speakol', 'capabilities' => ['API', 'CSV', 'MANUAL'], 'finalized_methods' => ['API', 'CSV', 'MANUAL']],
        'OUTBRAIN' => ['name' => 'Outbrain', 'capabilities' => ['API', 'CSV', 'MANUAL'], 'finalized_methods' => ['API', 'CSV', 'MANUAL']],
        'EXOCLICK' => ['name' => 'ExoClick', 'capabilities' => ['API', 'CSV', 'MANUAL'], 'finalized_methods' => ['API', 'CSV', 'MANUAL']],
        'ONETAG' => ['name' => 'OneTag', 'capabilities' => ['CSV', 'MANUAL', 'ESTIMATED'], 'finalized_methods' => ['CSV', 'MANUAL']],
        'CUSTOM_CSV' => ['name' => 'Custom CSV', 'capabilities' => ['CSV', 'MANUAL'], 'finalized_methods' => ['CSV', 'MANUAL']],
        'MANUAL_ADJUSTMENT' => ['name' => 'Manual Adjustment', 'capabilities' => ['MANUAL'], 'finalized_methods' => []],
    ],
];
