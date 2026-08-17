<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Technical retention execution
    |--------------------------------------------------------------------------
    |
    | This configuration governs only the explicit operational allowlist below.
    | A record is never eligible merely because it is old. Business history and
    | long-lived evidence remain outside the automated retention command unless
    | a future architecture-specific policy explicitly proves safe deletion.
    |
    */
    'chunk_size' => max(1, (int) env('DATA_RETENTION_CHUNK_SIZE', 500)),

    /*
    | Audit logs already have their own independent retention command. Keep the
    | existing conservative default (seven years / 2555 days) explicit here so
    | security evidence is never accidentally governed by an operational age.
    */
    'audit_logs_days' => max(1, (int) env('DATA_RETENTION_AUDIT_LOGS_DAYS', 2555)),

    'datasets' => [
        'synthetic_probe_results' => [
            'table' => 'synthetic_probe_results',
            'category' => 'BOUNDED OPERATIONAL',
            'retention_days' => max(1, (int) env('DATA_RETENTION_SYNTHETIC_PROBES_DAYS', 90)),
            'description' => 'Historical synthetic runtime probe observations; current serving state is stored elsewhere.',
        ],
        'privacy_diagnostic_evidence' => [
            'table' => 'privacy_diagnostic_evidence',
            'category' => 'BOUNDED OPERATIONAL',
            'retention_days' => max(1, (int) env('DATA_RETENTION_PRIVACY_DIAGNOSTIC_EVIDENCE_DAYS', 365)),
            'description' => 'Historical technical privacy diagnostics. Operator-verified CMP state is intentionally excluded.',
        ],
        'privacy_diagnostic_tokens' => [
            'table' => 'privacy_diagnostic_tokens',
            'category' => 'EPHEMERAL',
            'expired_grace_days' => max(1, (int) env('DATA_RETENTION_PRIVACY_DIAGNOSTIC_TOKEN_GRACE_DAYS', 30)),
            'description' => 'Expired one-time diagnostic authorization tokens after a conservative grace period.',
        ],
        'expired_user_invitations' => [
            'table' => 'user_invitations',
            'category' => 'EPHEMERAL',
            'retention_days' => max(1, (int) env('DATA_RETENTION_EXPIRED_INVITATIONS_DAYS', 180)),
            'description' => 'Only invitations that expired without ever being accepted.',
        ],
        'completed_job_batches' => [
            'table' => 'job_batches',
            'category' => 'EPHEMERAL',
            'retention_days' => max(1, (int) env('DATA_RETENTION_COMPLETED_JOB_BATCHES_DAYS', 90)),
            'description' => 'Framework batch metadata only after the batch is finished or cancelled; queued jobs and failures are excluded.',
        ],
    ],

    /*
    | These are deliberate permanent/business exclusions from the automated
    | operational retention command. The list is documentation and a regression
    | contract; DataRetentionManager has no delete rule for any of these tables.
    */
    'permanent_business_tables' => [
        'seller_declarations',
        'publisher_applications',
        'publisher_application_domain_claims',
        'publisher_application_revisions',
        'publisher_application_events',
        'publisher_application_legal_acceptances',
        'publisher_application_marketing_consents',
        'publisher_contracts',
        'financial_periods',
        'publisher_statements',
        'publisher_payments',
        'publisher_payment_settlements',
        'reconciliation_runs',
        'revenue_adjustments',
        'revenue_rules',
        'revenue_rule_versions',
        'hourly_reports',
        'daily_reports',
        'monthly_reports',
        'publisher_quality_profiles',
        'publisher_quality_review_runs',
        'publisher_quality_decisions',
        'support_tickets',
        'support_ticket_messages',
        'support_ticket_attachments',
        'campaigns',
        'campaign_approval_logs',
        'campaign_delivery_logs',
        'advertiser_invoices',
        'supply_chain_checks',
        'static_delivery_batches',
        'static_delivery_items',
        'static_global_artifact_changes',
    ],

    /*
    | Long-lived/current evidence deliberately not handled by data-retention:prune.
    | Some have separate lifecycle semantics; others are current-state rows rather
    | than append-only observations, so age alone is never a safe delete signal.
    */
    'preserved_evidence_tables' => [
        'audit_logs',
        'login_events',
        'failed_jobs',
        'horus_notifications',
        'google_cmp_evidence',
        'monetization_health_states',
        'system_heartbeats',
        'platform_controls',
        'gam_sync_runs',
        'gam_sync_logs',
        'gam_api_operations',
        'gam_errors',
        'prebid_setup_runs',
        'prebid_errors',
        'report_import_jobs',
        'report_import_files',
        'report_errors',
    ],
];
