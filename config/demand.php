<?php

return [
    'credential_reference_prefixes' => ['env:', 'file:'],
    'connection_timeout_seconds' => (int) env('DEMAND_CONNECTION_TIMEOUT', 10),
    'report_timeout_seconds' => (int) env('DEMAND_REPORT_TIMEOUT', 30),
    'csv_max_bytes' => (int) env('DEMAND_CSV_MAX_BYTES', 20 * 1024 * 1024),
    'direct_render_timeout_ms' => (int) env('DEMAND_DIRECT_RENDER_TIMEOUT_MS', 2500),
    'fallback_order' => ['GAM', 'MGID', 'TABOOLA', 'SPEAKOL', 'OUTBRAIN', 'HOUSE'],
    'allowed_script_origins' => [
        'MGID' => ['https://jsc.mgid.com', 'https://servicer.mgid.com'],
        'TABOOLA' => ['https://cdn.taboola.com', 'https://trc.taboola.com'],
        'SPEAKOL' => ['https://cdn.speakol.com', 'https://widget.speakol.com'],
        'OUTBRAIN' => ['https://widgets.outbrain.com', 'https://odb.outbrain.com'],
        'CUSTOM_NATIVE' => [],
        'CUSTOM_DISPLAY' => [],
        'CUSTOM_THIRD_PARTY_TAG' => [],
    ],
    'gam' => [
        'company_name_prefix' => 'Horus Native',
        'order_name_prefix' => 'Horus Native',
        'line_item_type' => 'PRICE_PRIORITY',
        'cost_type' => 'CPM',
        'default_cpm_micros' => 0,
    ],
];
