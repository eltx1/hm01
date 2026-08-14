<?php

return [
    'diagnostic_endpoint' => env('PRIVACY_DIAGNOSTIC_ENDPOINT', rtrim((string) env('APP_URL', 'https://app.horusmedia.net'), '/').'/privacy-diagnostics/report'),
    'diagnostic_ttl_minutes' => (int) env('PRIVACY_DIAGNOSTIC_TTL_MINUTES', 10),
    'diagnostic_max_bytes' => (int) env('PRIVACY_DIAGNOSTIC_MAX_BYTES', 4096),
    'probe_stale_days' => (int) env('PRIVACY_PROBE_STALE_DAYS', 30),
    'google_cmp_evidence_stale_days' => (int) env('GOOGLE_CMP_EVIDENCE_STALE_DAYS', 90),
];
