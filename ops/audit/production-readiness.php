<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$checks = [];
$metrics = [];

$add = static function (string $section, string $key, string $status, string $priority, string $message, array $evidence = []) use (&$checks): void {
    $checks[] = compact('section', 'key', 'status', 'priority', 'message', 'evidence');
};

$hasTable = static fn (string $table): bool => Schema::hasTable($table);
$hasColumn = static fn (string $table, string $column): bool => Schema::hasTable($table) && Schema::hasColumn($table, $column);

$count = static function (string $table, ?callable $scope = null) use ($hasTable): int {
    if (! $hasTable($table)) {
        return 0;
    }
    $query = DB::table($table);
    if ($scope) {
        $scope($query);
    }
    return (int) $query->count();
};

$groups = static function (string $table, string $column) use ($hasColumn): array {
    if (! $hasColumn($table, $column)) {
        return [];
    }
    return DB::table($table)
        ->selectRaw($column.' as value, count(*) as aggregate')
        ->groupBy($column)
        ->orderBy($column)
        ->get()
        ->mapWithKeys(fn ($row) => [(string) ($row->value ?? 'NULL') => (int) $row->aggregate])
        ->all();
};

$isPlaceholder = static function (?string $value): bool {
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return true;
    }
    foreach (['example.com', 'example.org', 'changeme', 'placeholder', 'your-', 'your_'] as $needle) {
        if (str_contains($value, $needle)) {
            return true;
        }
    }
    return false;
};

// Runtime foundation.
$env = (string) app()->environment();
$add('Runtime', 'app_environment', $env === 'production' ? 'PASS' : 'FAIL', 'P0', $env === 'production' ? 'Application is running in production mode.' : 'Application is not running in production mode.', ['environment' => $env]);
$debug = (bool) config('app.debug');
$add('Runtime', 'debug_disabled', !$debug ? 'PASS' : 'FAIL', 'P0', !$debug ? 'APP_DEBUG is disabled.' : 'APP_DEBUG is enabled in production.', ['debug' => $debug]);
$appUrl = (string) config('app.url');
$add('Runtime', 'https_app_url', str_starts_with($appUrl, 'https://') ? 'PASS' : 'FAIL', 'P0', str_starts_with($appUrl, 'https://') ? 'Application URL uses HTTPS.' : 'Application URL is not HTTPS.');

foreach ([
    ['session_driver', 'session.driver', 'database'],
    ['cache_driver', 'cache.default', 'database'],
    ['queue_driver', 'queue.default', 'database'],
] as [$key, $configKey, $expected]) {
    $actual = (string) config($configKey);
    $add('Runtime', $key, $actual === $expected ? 'PASS' : 'FAIL', $key === 'queue_driver' ? 'P0' : 'P1', $actual === $expected ? "$key uses the expected database backend." : "$key is not using the expected database backend.", ['actual' => $actual, 'expected' => $expected]);
}

// Database migration parity.
$codeMigrations = array_map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), glob(base_path('database/migrations/*.php')) ?: []);
$dbMigrations = $hasTable('migrations') ? DB::table('migrations')->pluck('migration')->map(static fn ($v) => (string) $v)->all() : [];
$pendingMigrations = array_values(array_diff($codeMigrations, $dbMigrations));
$add('Runtime', 'migrations_current', $pendingMigrations === [] ? 'PASS' : 'FAIL', 'P0', $pendingMigrations === [] ? 'All repository migrations are applied.' : 'Production has pending migrations.', ['code_migrations' => count($codeMigrations), 'applied_migrations' => count($dbMigrations), 'pending_count' => count($pendingMigrations)]);

// Scheduler heartbeat and database queue.
if ($hasTable('system_heartbeats')) {
    $heartbeat = DB::table('system_heartbeats')->orderByDesc('last_seen_at')->first();
    $lastSeen = $heartbeat?->last_seen_at ? Carbon::parse((string) $heartbeat->last_seen_at) : null;
    $age = $lastSeen ? max(0, now()->diffInSeconds($lastSeen, true)) : null;
    $staleAfter = (int) config('operations.heartbeat_stale_after_seconds', 180);
    $fresh = $age !== null && $age <= $staleAfter;
    $add('Operations', 'scheduler_heartbeat', $fresh ? 'PASS' : 'FAIL', 'P0', $fresh ? 'Scheduler heartbeat is fresh.' : 'Scheduler heartbeat is missing or stale.', ['age_seconds' => $age, 'stale_after_seconds' => $staleAfter]);
} else {
    $add('Operations', 'scheduler_heartbeat', 'FAIL', 'P0', 'System heartbeat table is missing.');
}

$queuedJobs = $count('jobs');
$failedJobs = $count('failed_jobs');
$metrics['queue'] = ['queued_jobs' => $queuedJobs, 'failed_jobs' => $failedJobs];
$add('Operations', 'failed_jobs', $failedJobs === 0 ? 'PASS' : 'FAIL', 'P1', $failedJobs === 0 ? 'No failed queue jobs are stored.' : 'Failed queue jobs require review before external pilot.', ['failed_jobs' => $failedJobs]);
$add('Operations', 'queue_backlog', $queuedJobs <= 25 ? 'PASS' : ($queuedJobs <= 100 ? 'BLOCKED' : 'FAIL'), $queuedJobs <= 25 ? 'P3' : 'P1', $queuedJobs <= 25 ? 'Database queue backlog is within the audit threshold.' : 'Database queue backlog is elevated.', ['queued_jobs' => $queuedJobs, 'threshold' => 25]);

// Static delivery.
$staticDriver = (string) config('static-delivery.driver');
$staticDryRun = (bool) config('static-delivery.cloudflare.dry_run');
$add('Static Edge', 'delivery_driver', in_array($staticDriver, ['external-pages-sync', 'cloudflare'], true) ? 'PASS' : 'BLOCKED', 'P1', in_array($staticDriver, ['external-pages-sync', 'cloudflare'], true) ? 'Production static delivery uses an external edge driver.' : 'Production static delivery is not using an external edge driver.', ['driver' => $staticDriver]);
$add('Static Edge', 'delivery_not_dry_run', !$staticDryRun ? 'PASS' : 'FAIL', 'P0', !$staticDryRun ? 'Static delivery dry-run is disabled.' : 'Static delivery is still in dry-run mode.');
if ($hasTable('static_delivery_batches')) {
    $latestBatch = DB::table('static_delivery_batches')->orderByDesc('created_at')->first();
    $latestStatus = $latestBatch ? (string) $latestBatch->status : null;
    $ok = $latestStatus === 'DEPLOYED';
    $add('Static Edge', 'latest_delivery_batch', $latestBatch === null ? 'NOT_CONFIGURED' : ($ok ? 'PASS' : 'BLOCKED'), $latestBatch === null ? 'P1' : ($ok ? 'P3' : 'P1'), $latestBatch === null ? 'No static delivery batch exists yet.' : ($ok ? 'Latest static delivery batch is deployed.' : 'Latest static delivery batch is not deployed.'), ['status' => $latestStatus, 'attempts' => $latestBatch ? (int) $latestBatch->attempts : null]);
    $metrics['static_delivery_statuses'] = $groups('static_delivery_batches', 'status');
}

// Security and public registration policy.
$add('Security', 'public_registration', (bool) config('publisher-applications.public_registration_enabled') ? 'PASS' : 'BLOCKED', 'P1', (bool) config('publisher-applications.public_registration_enabled') ? 'Public Publisher registration is enabled.' : 'Public Publisher registration is disabled.');
$add('Security', 'email_verification_policy', !(bool) config('security.auth.email_verification_required') ? 'PASS' : 'FAIL', 'P1', !(bool) config('security.auth.email_verification_required') ? 'Production email verification remains non-mandatory by owner policy.' : 'Email verification was unexpectedly re-enabled.');
$add('Security', 'admin_2fa_policy', !(bool) config('security.auth.admin_2fa_required') ? 'PASS' : 'FAIL', 'P1', !(bool) config('security.auth.admin_2fa_required') ? 'Production Admin 2FA remains non-mandatory by owner policy.' : 'Admin 2FA was unexpectedly re-enabled.');
$hsts = (int) config('security.headers.hsts.max_age', config('security.hsts.max_age', 0));
$add('Security', 'hsts', $hsts >= 31536000 ? 'PASS' : 'BLOCKED', 'P1', $hsts >= 31536000 ? 'HSTS production policy is enabled for at least one year.' : 'HSTS policy is weaker than the production target.', ['max_age' => $hsts]);

$legal = (array) config('publisher-applications.legal_documents', []);
$legalValid = $legal !== [];
foreach ($legal as $document) {
    $url = (string) ($document['url'] ?? '');
    $version = trim((string) ($document['version'] ?? ''));
    $required = (bool) ($document['required'] ?? false);
    if ($required && (!str_starts_with($url, 'https://') || $version === '')) {
        $legalValid = false;
    }
}
$add('Security', 'legal_documents', $legalValid ? 'PASS' : 'FAIL', 'P1', $legalValid ? 'Required Publisher legal documents have HTTPS URLs and versions.' : 'Publisher legal document configuration is incomplete.', ['document_count' => count($legal)]);

// Mail configuration; no credential values are emitted.
$mailer = (string) config('mail.default');
$mailHost = (string) config('mail.mailers.smtp.host');
$mailFrom = (string) config('mail.from.address');
$mailConfigured = !in_array($mailer, ['log', 'array'], true) && !$isPlaceholder($mailHost) && !$isPlaceholder($mailFrom) && filter_var($mailFrom, FILTER_VALIDATE_EMAIL) !== false;
$add('Notifications', 'production_mail', $mailConfigured ? 'PASS' : 'NOT_CONFIGURED', 'P1', $mailConfigured ? 'Production mail transport and sender are configured.' : 'Production mail transport or sender is still missing/placeholder.', ['mailer' => $mailer, 'host_configured' => !$isPlaceholder($mailHost), 'sender_configured' => !$isPlaceholder($mailFrom)]);
$errorRecipientConfigured = filled(config('operations.error_notification_email'));
$add('Notifications', 'error_recipient', $errorRecipientConfigured ? 'PASS' : 'NOT_CONFIGURED', 'P2', $errorRecipientConfigured ? 'Operational error notification recipient is configured.' : 'Operational error notification recipient is not configured.');

// THOTH is optional by product policy; report readiness without making it a lifecycle gate.
$thothSetting = $hasTable('thoth_settings') ? DB::table('thoth_settings')->where('id', 1)->first() : null;
$thothEnabled = $thothSetting ? (bool) $thothSetting->enabled : false;
$thothProvider = $thothSetting ? strtoupper((string) $thothSetting->active_provider) : strtoupper((string) config('thoth.default_provider'));
$providerRow = $hasTable('ai_provider_connections') ? DB::table('ai_provider_connections')->where('provider', $thothProvider)->orderByDesc('updated_at')->first() : null;
$providerCredentialConfigured = $providerRow && !empty($providerRow->encrypted_credential);
if (!$providerCredentialConfigured) {
    $providerCredentialConfigured = filled(config('thoth.credentials.'.strtolower($thothProvider)));
}
$providerFresh = $providerRow && (string) $providerRow->status === 'CONNECTED' && $providerRow->last_connected_at && Carbon::parse((string) $providerRow->last_connected_at)->gte(now()->subMinutes((int) config('thoth.connection_max_age_minutes', 1440)));
$thothReady = $thothEnabled && $providerCredentialConfigured && $providerFresh;
$add('THOTH', 'website_quality_advisor', $thothReady ? 'PASS' : 'NOT_CONFIGURED', 'P2', $thothReady ? 'THOTH is enabled with a recently connected provider.' : 'THOTH is optional but not fully ready in production.', ['enabled' => $thothEnabled, 'provider' => $thothProvider, 'credential_configured' => $providerCredentialConfigured, 'connection_ready' => (bool) $providerFresh]);
if ($hasTable('site_quality_review_runs')) {
    $metrics['site_quality_review_statuses'] = $groups('site_quality_review_runs', 'status');
}

// GAM, demand and Prebid readiness.
$gamEnabled = $count('gam_connections', fn ($q) => $q->where('is_enabled', 1)->whereNull('deleted_at'));
$gamReal = $count('gam_connections', fn ($q) => $q->where('is_enabled', 1)->where('dry_run_default', 0)->whereNull('deleted_at'));
$gamHealthyReal = $count('gam_connections', fn ($q) => $q->where('is_enabled', 1)->where('dry_run_default', 0)->where('health_status', 'HEALTHY')->whereNull('deleted_at'));
$metrics['gam'] = ['enabled' => $gamEnabled, 'non_dry_run' => $gamReal, 'healthy_non_dry_run' => $gamHealthyReal, 'health_statuses' => $groups('gam_connections', 'health_status')];
$add('Monetization', 'gam', $gamHealthyReal > 0 ? 'PASS' : ($gamEnabled > 0 ? 'BLOCKED' : 'NOT_CONFIGURED'), $gamHealthyReal > 0 ? 'P3' : 'P2', $gamHealthyReal > 0 ? 'At least one enabled healthy GAM connection is live and not dry-run.' : ($gamEnabled > 0 ? 'GAM exists but no enabled healthy non-dry-run connection is available.' : 'No enabled GAM connection is configured.'), ['enabled' => $gamEnabled, 'non_dry_run' => $gamReal, 'healthy_non_dry_run' => $gamHealthyReal]);

$demandApproved = $count('demand_accounts', fn ($q) => $q->where('is_enabled', 1)->where('approval_status', 'APPROVED')->whereNull('deleted_at'));
$demandTested = $count('demand_accounts', fn ($q) => $q->where('is_enabled', 1)->where('approval_status', 'APPROVED')->whereNotNull('last_tested_at')->whereNull('deleted_at'));
$metrics['demand'] = ['enabled_approved_accounts' => $demandApproved, 'tested_enabled_approved_accounts' => $demandTested, 'approval_statuses' => $groups('demand_accounts', 'approval_status')];
$add('Monetization', 'demand_accounts', $demandTested > 0 ? 'PASS' : ($demandApproved > 0 ? 'BLOCKED' : 'NOT_CONFIGURED'), $demandTested > 0 ? 'P3' : 'P2', $demandTested > 0 ? 'At least one enabled approved Demand account has been tested.' : ($demandApproved > 0 ? 'Approved Demand exists but no enabled account has a test timestamp.' : 'No enabled approved Demand account is configured.'), ['enabled_approved' => $demandApproved, 'tested' => $demandTested]);

$prebidEnabled = $count('prebid_settings', fn ($q) => $q->where('enabled', 1));
$metrics['prebid'] = ['enabled_settings' => $prebidEnabled, 'bidders' => $count('prebid_bidders'), 'adapters' => $count('prebid_adapters')];
$add('Monetization', 'prebid', $prebidEnabled > 0 ? 'PASS' : 'NOT_CONFIGURED', 'P3', $prebidEnabled > 0 ? 'At least one Prebid runtime setting is enabled.' : 'Prebid is not currently enabled; this is not a blocker when another monetization path is used.', ['enabled_settings' => $prebidEnabled]);

$realMonetizationPath = $gamHealthyReal > 0 || $demandTested > 0;
$add('Monetization', 'real_revenue_path', $realMonetizationPath ? 'PASS' : 'BLOCKED', 'P1', $realMonetizationPath ? 'At least one tested real monetization path is available for the Publisher pilot.' : 'No tested real monetization path is proven yet; the first revenue pilot cannot complete.', ['healthy_live_gam' => $gamHealthyReal, 'tested_approved_demand' => $demandTested]);

// Sites and supply chain, aggregate-only.
$metrics['publishers'] = ['total' => $count('publishers')];
$metrics['sites'] = ['total' => $count('sites'), 'statuses' => $groups('sites', 'status')];
$managedSellers = $count('seller_declarations', fn ($q) => $q->where('identity_source', 'HORUS_MANAGED'));
$activeManagedSellers = $count('seller_declarations', fn ($q) => $q->where('identity_source', 'HORUS_MANAGED')->where('status', 'ACTIVE'));
$publicManagedMissingIdentity = 0;
if ($hasTable('seller_declarations')) {
    $publicManagedMissingIdentity = (int) DB::table('seller_declarations')
        ->where('identity_source', 'HORUS_MANAGED')
        ->where('is_confidential', 0)
        ->where(function ($q) {
            $q->whereNull('name')->orWhere('name', '')->orWhereNull('domain')->orWhere('domain', '');
        })->count();
}
$metrics['seller_declarations'] = ['managed_total' => $managedSellers, 'managed_active' => $activeManagedSellers, 'statuses' => $groups('seller_declarations', 'status'), 'review_statuses' => $groups('seller_declarations', 'review_status')];
$add('Supply Chain', 'managed_public_identity', $publicManagedMissingIdentity === 0 ? 'PASS' : 'FAIL', 'P0', $publicManagedMissingIdentity === 0 ? 'All non-confidential managed seller declarations have public name/domain data.' : 'One or more non-confidential managed seller declarations are missing name/domain.', ['missing_public_identity_count' => $publicManagedMissingIdentity]);
$metrics['supply_chain_checks'] = ['statuses' => $groups('supply_chain_checks', 'status'), 'last_24h' => $count('supply_chain_checks', fn ($q) => $q->where('checked_at', '>=', now()->subDay()))];

// Reporting and finance.
$reportEnabled = $count('report_source_connections', fn ($q) => $q->where('is_enabled', 1));
$reportFinalized = $count('report_source_connections', fn ($q) => $q->where('is_enabled', 1)->whereNotNull('last_finalized_import_at'));
$metrics['reporting'] = ['enabled_connections' => $reportEnabled, 'connections_with_finalized_import' => $reportFinalized, 'connection_statuses' => $groups('report_source_connections', 'status'), 'import_statuses' => $groups('report_import_jobs', 'status'), 'reconciliation_statuses' => $groups('reconciliation_runs', 'status')];
$add('Reporting', 'financial_reporting_source', $reportFinalized > 0 ? 'PASS' : ($reportEnabled > 0 ? 'BLOCKED' : 'NOT_CONFIGURED'), 'P1', $reportFinalized > 0 ? 'At least one enabled reporting connection has a finalized import.' : ($reportEnabled > 0 ? 'Reporting connections exist but no finalized import has been proven.' : 'No enabled reporting source connection is configured.'), ['enabled_connections' => $reportEnabled, 'finalized_connections' => $reportFinalized]);

$today = now()->toDateString();
$currentTerms = $count('publisher_contracts', function ($q) use ($today) {
    $q->whereNull('deleted_at')->whereIn('status', ['SIGNED', 'ACTIVE'])
        ->where(fn ($w) => $w->whereNull('starts_at')->orWhereDate('starts_at', '<=', $today))
        ->where(fn ($w) => $w->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today));
});
$activeRevenueRules = $count('revenue_rules', fn ($q) => $q->where('is_active', 1));
$metrics['commercial'] = ['current_terms' => $currentTerms, 'active_revenue_rules' => $activeRevenueRules];
$commercialReady = $currentTerms > 0 && $activeRevenueRules > 0;
$add('Finance', 'commercial_terms_and_revenue_rules', $commercialReady ? 'PASS' : 'BLOCKED', 'P1', $commercialReady ? 'Current commercial terms and active Revenue Rules exist.' : 'The Publisher-to-revenue pilot still needs current commercial terms and an active Revenue Rule.', ['current_terms' => $currentTerms, 'active_revenue_rules' => $activeRevenueRules]);

$metrics['finance'] = [
    'financial_period_statuses' => $groups('financial_periods', 'status'),
    'statements' => $count('publisher_statements'),
    'payment_profiles' => $count('publisher_payment_profiles'),
    'payments' => $count('publisher_payments'),
];
$add('Finance', 'statement_pipeline', $count('publisher_statements') > 0 ? 'PASS' : 'NOT_CONFIGURED', 'P2', $count('publisher_statements') > 0 ? 'At least one Publisher statement exists.' : 'No Publisher statement has been produced yet; this remains part of the first revenue pilot.', ['statement_count' => $count('publisher_statements')]);

// Advertiser scope: do not make it a Publisher-pilot blocker unless active campaigns exist.
$campaignsEnabled = (bool) config('campaigns.enabled');
$activeCampaigns = $count('campaigns', fn ($q) => $q->whereIn('status', ['APPROVED', 'ACTIVE', 'DELIVERING']));
$scanner = trim((string) config('campaigns.malware_scanner_binary'));
$scannerReady = $scanner !== '' && is_executable($scanner);
$campaignStatus = !$campaignsEnabled || $activeCampaigns === 0 ? 'INFO' : ($scannerReady ? 'PASS' : 'FAIL');
$campaignPriority = $activeCampaigns > 0 && !$scannerReady ? 'P1' : 'P3';
$add('Advertiser', 'creative_malware_scanner', $campaignStatus, $campaignPriority, !$campaignsEnabled ? 'Advertiser campaigns are disabled.' : ($activeCampaigns === 0 ? 'Advertiser campaigns are enabled but no active campaign makes scanner readiness a Publisher-pilot blocker.' : ($scannerReady ? 'Active advertiser campaign scope has an executable malware scanner.' : 'Active advertiser campaigns exist without an executable malware scanner; fail-closed upload policy will block unsafe activation.')), ['campaigns_enabled' => $campaignsEnabled, 'active_campaigns' => $activeCampaigns, 'scanner_configured' => $scanner !== '', 'scanner_executable' => $scannerReady]);

// Privacy/Traffic Gate remain optional controls, report only.
$add('Optional Controls', 'traffic_gate', (bool) config('traffic-gate.enabled') ? 'INFO' : 'INFO', 'P3', (bool) config('traffic-gate.enabled') ? 'Client Traffic Gate is enabled.' : 'Client Traffic Gate is disabled; it is optional and not a production-readiness blocker.', ['enabled' => (bool) config('traffic-gate.enabled')]);
$add('Optional Controls', 'registration_turnstile', (bool) config('publisher-applications.turnstile.enabled') ? 'INFO' : 'INFO', 'P3', (bool) config('publisher-applications.turnstile.enabled') ? 'Registration Turnstile is enabled.' : 'Registration Turnstile is disabled by current product policy.', ['enabled' => (bool) config('publisher-applications.turnstile.enabled')]);

$blocking = array_values(array_filter($checks, static fn (array $check): bool => in_array($check['status'], ['FAIL', 'BLOCKED'], true) && in_array($check['priority'], ['P0', 'P1'], true)));
$summary = [
    'overall' => $blocking === [] ? 'PILOT_READY' : 'NOT_READY',
    'blocking_count' => count($blocking),
    'counts' => array_count_values(array_map(static fn (array $check): string => $check['status'], $checks)),
];

$release = [];
$marker = base_path('.horus-release');
if (is_file($marker)) {
    foreach (file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
        if (in_array($key, ['release_id', 'artifact_sha256', 'deployed_at'], true) && is_string($value)) {
            $release[$key] = $value;
        }
    }
}

$report = [
    'schema_version' => 1,
    'generated_at' => now()->toIso8601String(),
    'release' => $release,
    'summary' => $summary,
    'checks' => $checks,
    'metrics' => $metrics,
    'privacy' => 'Aggregate-only report. No credentials, secret values, Publisher names, email addresses, payment details, raw URLs, file contents, or private IDs are emitted.',
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
