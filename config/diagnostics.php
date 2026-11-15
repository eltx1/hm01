<?php

return [
    // No collection is enabled by these settings. Each site must also explicitly opt in.
    'collector_url' => env('HORUS_DIAGNOSTICS_COLLECTOR_URL', 'https://metrics.horusmedia.net'),
    'account_id' => env('HORUS_DIAGNOSTICS_ACCOUNT_ID'),
    'api_token_reference' => env('HORUS_DIAGNOSTICS_TOKEN_REFERENCE'),
    'dataset' => 'horus_platform_diagnostics_v1',
];
