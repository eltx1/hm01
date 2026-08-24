<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$checks = [];
$metrics = [];

$add = static function (string $section, string $key, string $status, string $priority, string $message, array $evidence = []) use (&$checks): void {
    $checks[] = compact('section', 'key', 'status', 'priority', 'message', 'evidence');
};

$exists = static fn (string $table): bool => Schema::hasTable($table);
$hasColumn = static fn (string $table, string $column): bool => Schema::hasTable($table) && Schema::hasColumn($table, $column);

$count = static function (string $table, ?callable $scope = null) use ($exists): int {
    if (!$exists($table)) {
        return 0;
    }
    try {
        $query = DB::table($table);
        if ($scope) {
            $scope($query);
        }
        return (int) $query->count();
    } catch (Throwable) {
        return 0;
    }
};

$groups = static function (string $table, string $column) use ($hasColumn): array {
    if (!$hasColumn($table, $column)) {
        return [];
    }
    try {
        return DB::table($table)
            ->selectRaw("{$column} as value, count(*) as aggregate")
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) ($row->value ?? 'NULL') => (int) $row->aggregate])
            ->all();
    } catch (Throwable) {
        return [];
    }
};

$isPlaceholder = static function (?string $value): bool {
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return true;
    }
    foreach (['example.com', 'example.org', 'placeholder', 'changeme', 'your-', 'your_'] as $needle) {
        if (str_contains($value, $needle)) {
            return true;
        }
    }
    return false;
};

// Runtime / deployment foundation.
$environment = (string) app()->environment();
$add('Runtime', 'app_environment', $environment === 'production' ? 'PASS' : 'FAIL', 'P0', $environment === 'production' ? 'Application is running in production mode.' : 'Application is not running in production mode.', ['environment' => $environment]);
$debug = (bool) config('app.debug');
$add('Runtime', 'debug_disabled', !$debug ? 'PASS' : 'FAIL', 'P0', !$debug ? 'APP_DEBUG is disabled.' : 'APP_DEBUG is enabled in production.', ['debug' => $debug]);
$appUrl = (string) config('app.url');
$add('Runtime', 'https_app_url', str_starts_with($appUrl, 'https://') ? 'PASS' : 'FAIL', 'P0', str_starts_with($appUrl, 'https://') ? 'Application URL uses HTTPS.' : 'Application URL is not HTTPS.');

foreach ([
    ['session_driver', 'session.driver', 'database', 'P1'],
    ['cache_driver', 'cache.default', 'database', 'P1'],
    ['queue_driver', 'queue.default', 'database', 'P0'],
] as [$key, $configKey, $expected, $priority]) {
    $actual = (string) config($configKey);
    $add('Runtime', $key, $actual === $expected ? 'PASS' : 'FAIL', $priority, $actual === $expected ? "{$key} uses the expected database backend." : "{$key} is not using the expected database backend.", ['actual' => $actual, 'expected' => $expected]);
}

$codeMigrations = array_map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), glob(base_path('database/migrations/*.php')) ?: []);
$dbMigrations = $exists('migrations') ? DB::table('migrations')->pluck('migration')->map(static fn ($v) => (string) $v)->all() : [];
$pendingMigrations = array_values(array_diff($codeMigrations, $dbMigrations));
$add('Runtime', 'migrations_current', $pendingMigrations === [] ? 'PASS' : 'FAIL', 'P0', $pendingMigrations === [] ? 'All repository migrations are applied.' : 'Production has pending migrations.', ['code_migrations' => count($codeMigrations), 'applied_migrations' => count($dbMigrations), 'pending_count' => count($pendingMigrations)]);

// Operations / scheduler / queue.
$heartbeat = $exists('system_heartbeats') ? DB::table('system_heartbeats')->orderByDesc('last_seen_at')->first() : null;
$lastSeen = $heartbeat?->last_seen_at ? Carbon::parse((string) $heartbeat->last_seen_at) : null;
$heartbeatAge = $lastSeen ? max(0, (int) now()->diffInSeconds($lastSeen, true)) : null;
$staleAfter = (int) config('operations.heartbeat_stale_after_seconds', 180);
$heartbeatFresh = $heartbeatAge !== null && $heartbeatAge <= $staleAfter;
$add('Operations', 'scheduler_heartbeat', $heartbeatFresh ? 'PASS' : 'FAIL', 'P0', $heartbeatFresh ? 'Scheduler heartbeat is fresh.' : 'Scheduler heartbeat is missing or stale.', ['age_seconds' => $heartbeatAge, 'stale_after_seconds' => $staleAfter]);

$queuedJobs = $count('jobs');
$failedJobs = $count('failed_jobs');
$metrics['queue'] = ['queued_jobs' => $queuedJobs, 'failed_jobs' => $failedJobs];
$add('Operations', 'failed_jobs', $failedJobs === 0 ? 'PASS' : 'FAIL', 'P1', $failedJobs === 0 ? 'No failed queue jobs are stored.' : 'Failed queue jobs require review before the external pilot.', ['failed_jobs' => $failedJobs]);
$add('Operations', 'queue_backlog', $queuedJobs <= 25 ? 'PASS' : ($queuedJobs <= 100 ? 'BLOCKED' : 'FAIL'), $queuedJobs <= 25 ? 'P3' : 'P1', $queuedJobs <= 25 ? 'Database queue backlog is within the audit threshold.' : 'Database queue backlog is elevated.', ['queued_jobs' => $queuedJobs, 'warning_threshold' => 25]);

// Static edge. Exact artifact byte comparison is performed by Verify production live.
$staticDriver = (string) config('static-delivery.driver');
$staticDryRun = (bool) config('static-delivery.cloudflare.dry_run');
$edgeDriverReady = in_array($staticDriver, ['external-pages-sync', 'cloudflare'], true);
$add('Static Edge', 'delivery_driver', $edgeDriverReady ? 'PASS' : 'BLOCKED', 'P1', $edgeDriverReady ? 'Production static delivery uses an external edge driver.' : 'Production static delivery is not using an external edge driver.', ['driver' => $staticDriver]);
$add('Static Edge', 'delivery_not_dry_run', !$staticDryRun ? 'PASS' : 'FAIL', 'P0', !$staticDryRun ? 'Static delivery dry-run is disabled.' : 'Static delivery is still in dry-run mode.');
$latestBatch = $exists('static_delivery_batches') ? DB::table('static_delivery_batches')->orderByDesc('created_at')->first() : null;
$latestBatchStatus = $latestBatch ? (string) $latestBatch->status : null;
$add('Static Edge', 'latest_delivery_batch', $latestBatchStatus === 'DEPLOYED' ? 'PASS' : ($latestBatch ? 'BLOCKED' : 'NOT_CONFIGURED'), $latestBatchStatus === 'DEPLOYED' ? 'P3' : 'P1', $latestBatchStatus === 'DEPLOYED' ? 'Latest static delivery batch is deployed.' : ($latestBatch ? 'Latest static delivery batch is not deployed.' : 'No static delivery batch exists yet.'), ['status' => $latestBatchStatus, 'attempts' => $latestBatch ? (int) $latestBatch->attempts : null]);
$metrics['static_delivery_statuses'] = $groups('static_delivery_batches', 'status');

// Security and product-policy invariants.
$registrationEnabled = (bool) config('publisher-applications.public_registration_enabled');
$add('Security', 'public_registration', $registrationEnabled ? 'PASS' : 'BLOCKED', 'P1', $registrationEnabled ? 'Public Publisher registration is enabled.' : 'Public Publisher registration is disabled.');
$emailVerificationRequired = (bool) config('security.authentication.email_verification_required');
$admin2faRequired = (bool) config('security.authentication.administrator_2fa_required');
$add('Security', 'email_verification_policy', !$emailVerificationRequired ? 'PASS' : 'FAIL', 'P1', !$emailVerificationRequired ? 'Email verification remains non-mandatory by owner policy.' : 'Email verification was unexpectedly re-enabled.');
$add('Security', 'admin_2fa_policy', !$admin2faRequired ? 'PASS' : 'FAIL', 'P1', !$admin2faRequired ? 'Admin 2FA remains non-mandatory by owner policy.' : 'Admin 2FA was unexpectedly re-enabled.');
$hstsMaxAge = (int) config('security.headers.hsts_max_age', 0);
$add('Security', 'hsts', $hstsMaxAge >= 31536000 ? 'PASS' : 'BLOCKED', 'P1', $hstsMaxAge >= 31536000 ? 'HSTS is configured for at least one year.' : 'HSTS is weaker than the production target.', ['max_age' => $hstsMaxAge]);

$legalDocuments = (array) config('publisher-applications.legal_documents', []);
$legalValid = $legalDocuments !== [];
foreach ($legalDocuments as $document) {
    if ((bool) ($document['required'] ?? false)) {
        $legalValid = $legalValid && str_starts_with((string) ($document['url'] ?? ''), 'https://') && trim((string) ($document['version'] ?? '')) !== '';
    }
}
$add('Security', 'legal_documents', $legalValid ? 'PASS' : 'FAIL', 'P1', $legalValid ? 'Required Publisher legal documents have HTTPS URLs and versions.' : 'Publisher legal document configuration is incomplete.', ['document_count' => count($legalDocuments)]);

// Mail / operational notifications. No secret values are emitted.
$mailer = (string) config('mail.default');
$mailHost = (string) config('mail.mailers.smtp.host');
$mailFrom = (string) config('mail.from.address');
$mailConfigured = !in_array($mailer, ['log', 'array'], true) && !$isPlaceholder($mailHost) && !$isPlaceholder($mailFrom) && filter_var($mailFrom, FILTER_VALIDATE_EMAIL) !== false;
$add('Notifications', 'production_mail', $mailConfigured ? 'PASS' : 'NOT_CONFIGURED', 'P1', $mailConfigured ? 'Production mail transport and sender are configured.' : 'Production mail transport or sender is missing/placeholder.', ['mailer' => $mailer, 'host_configured' => !$isPlaceholder($mailHost), 'sender_configured' => !$isPlaceholder($mailFrom)]);
$errorRecipientConfigured = filled(config('operations.error_notification_email'));
$add('Notifications', 'error_recipient', $errorRecipientConfigured ? 'PASS' : 'NOT_CONFIGURED', 'P2', $errorRecipientConfigured ? 'Operational error notification recipient is configured.' : 'Operational error notification recipient is not configured.');

// THOTH is deliberately optional and never a lifecycle blocker.
$thothSetting = $exists('thoth_settings') ? DB::table('thoth_settings')->where('id', 1)->first() : null;
$thothEnabled = $thothSetting ? (bool) $thothSetting->enabled : false;
$thothProvider = strtoupper((string) ($thothSetting?->active_provider ?: config('thoth.default_provider')));
$providerRow = $exists('ai_provider_connections') ? DB::table('ai_provider_connections')->where('provider', $thothProvider)->orderByDesc('updated_at')->first() : null;
$providerCredential = (bool) ($providerRow && !empty($providerRow->encrypted_credential));
$providerCredential = $providerCredential || filled(config('thoth.credentials.'.strtolower($thothProvider)));
$providerFresh = (bool) ($providerRow && (string) $providerRow->status === 'CONNECTED' && $providerRow->last_connected_at && Carbon::parse((string) $providerRow->last_connected_at)->gte(now()->subMinutes((int) config('thoth.connection_max_age_minutes', 1440))));
$thothReady = $thothEnabled && $providerCredential && $providerFresh;
$add('THOTH', 'website_quality_advisor', $thothReady ? 'PASS' : 'NOT_CONFIGURED', 'P2', $thothReady ? 'THOTH is enabled with a recently connected provider.' : 'THOTH is optional but not fully ready in production.', ['enabled' => $thothEnabled, 'provider' => $thothProvider, 'credential_configured' => $providerCredential, 'connection_ready' => $providerFresh]);
$metrics['site_quality_review_statuses'] = $groups('site_quality_review_runs', 'status');

// Monetization integrations.
$gamEnabled = $count('gam_connections', fn ($q) => $q->where('is_enabled', 1)->whereNull('deleted_at'));
$gamReal = $count('gam_connections', fn ($q) => $q->where('is_enabled', 1)->where('dry_run_default', 0)->whereNull('deleted_at'));
$gamHealthyReal = $count('gam_connections', fn ($q) => $q->where('is_enabled', 1)->where('dry_run_default', 0)->where('health_status', 'HEALTHY')->whereNull('deleted_at'));
$metrics['gam'] = ['enabled' => $gamEnabled, 'non_dry_run' => $gamReal, 'healthy_non_dry_run' => $gamHealthyReal, 'health_statuses' => $groups('gam_connections', 'health_status')];
$add('Monetization', 'gam', $gamHealthyReal > 0 ? 'PASS' : ($gamEnabled > 0 ? 'BLOCKED' : 'NOT_CONFIGURED'), 'P2', $gamHealthyReal > 0 ? 'At least one enabled healthy GAM connection is live and not dry-run.' : ($gamEnabled > 0 ? 'GAM exists but no enabled healthy non-dry-run connection is available.' : 'No enabled GAM connection is configured.'), ['enabled' => $gamEnabled, 'non_dry_run' => $gamReal, 'healthy_non_dry_run' => $gamHealthyReal]);

$demandApproved = $count('demand_accounts', fn ($q) => $q->where('is_enabled', 1)->where('approval_status', 'APPROVED')->whereNull('deleted_at'));
$demandTested = $count('demand_accounts', fn ($q) => $q->where('is_enabled', 1)->where('approval_status', 'APPROVED')->whereNotNull('last_tested_at')->whereNull('deleted_at'));
$metrics['demand'] = ['enabled_approved_accounts' => $demandApproved, 'tested_enabled_approved_accounts' => $demandTested, 'approval_statuses' => $groups('demand_accounts', 'approval_status')];
$add('Monetization', 'demand_accounts', $demandTested > 0 ? 'PASS' : ($demandApproved > 0 ? 'BLOCKED' : 'NOT_CONFIGURED'), 'P2', $demandTested > 0 ? 'At least one enabled approved Demand account has been tested.' : ($demandApproved > 0 ? 'Approved Demand exists but no enabled account has a test timestamp.' : 'No enabled approved Demand account is configured.'), ['enabled_approved' => $demandApproved, 'tested' => $demandTested]);

$prebidEnabled = $count('prebid_settings', fn ($q) => $q->where('enabled', 1));
$metrics['prebid'] = ['enabled_settings' => $prebidEnabled, 'bidders' => $count('prebid_bidders'), 'adapters' => $count('prebid_adapters')];
$add('Monetization', 'prebid', $prebidEnabled > 0 ? 'PASS' : 'NOT_CONFIGURED', 'P3', $prebidEnabled > 0 ? 'At least one Prebid runtime setting is enabled.' : 'Prebid is not enabled; another monetization path may be used.', ['enabled_settings' => $prebidEnabled]);

$realMonetizationPath = $gamHealthyReal > 0 || $demandTested > 0;
$add('Monetization', 'real_revenue_path', $realMonetizationPath ? 'PASS' : 'BLOCKED', 'P1', $realMonetizationPath ? 'At least one tested real monetization path is available for the Publisher pilot.' : 'No tested real monetization path is proven yet; the first revenue pilot cannot complete.', ['healthy_live_gam' => $gamHealthyReal, 'tested_approved_demand' => $demandTested]);

// Publisher / Site / supply-chain aggregate integrity.
$metrics['publishers'] = ['total' => $count('publishers')];
$metrics['sites'] = ['total' => $count('sites'), 'statuses' => $groups('sites', 'status')];
$managedSellers = $count('seller_declarations', fn ($q) => $q->where('identity_source', 'HORUS_MANAGED'));
$activeManagedSellers = $count('seller_declarations', fn ($q) => $q->where('identity_source', 'HORUS_MANAGED')->where('status', 'ACTIVE'));
$missingPublicIdentity = $count('seller_declarations', fn ($q) => $q->where('identity_source', 'HORUS_MANAGED')->where('is_confidential', 0)->where(fn ($w) => $w->whereNull('name')->orWhere('name', '')->orWhereNull('domain')->orWhere('domain', '')));
$metrics['seller_declarations'] = ['managed_total' => $managedSellers, 'managed_active' => $activeManagedSellers, 'statuses' => $groups('seller_declarations', 'status'), 'review_statuses' => $groups('seller_declarations', 'review_status')];
$add('Supply Chain', 'managed_public_identity', $missingPublicIdentity === 0 ? 'PASS' : 'FAIL', 'P0', $missingPublicIdentity === 0 ? 'All non-confidential managed seller declarations have public name/domain data.' : 'One or more non-confidential managed seller declarations are missing name/domain.', ['missing_public_identity_count' => $missingPublicIdentity]);
$metrics['supply_chain_checks'] = ['statuses' => $groups('supply_chain_checks', 'status'), 'last_24h' => $count('supply_chain_checks', fn ($q) => $q->where('checked_at', '>=', now()->subDay()))];

// Reporting / finance readiness for a real revenue trace.
$reportEnabled = $count('report_source_connections', fn ($q) => $q->where('is_enabled', 1));
$reportFinalized = $count('report_source_connections', fn ($q) => $q->where('is_enabled', 1)->whereNotNull('last_finalized_import_at'));
$metrics['reporting'] = ['enabled_connections' => $reportEnabled, 'connections_with_finalized_import' => $reportFinalized, 'connection_statuses' => $groups('report_source_connections', 'status'), 'import_statuses' => $groups('report_import_jobs', 'status'), 'reconciliation_statuses' => $groups('reconciliation_runs', 'status')];
$add('Reporting', 'financial_reporting_source', $reportFinalized > 0 ? 'PASS' : ($reportEnabled > 0 ? 'BLOCKED' : 'NOT_CONFIGURED'), 'P1', $reportFinalized > 0 ? 'At least one enabled reporting connection has a finalized import.' : ($reportEnabled > 0 ? 'Reporting connections exist but no finalized import has been proven.' : 'No enabled reporting source connection is configured.'), ['enabled_connections' => $reportEnabled, 'finalized_connections' => $reportFinalized]);

$today = now()->toDateString();
$currentTerms = $count('publisher_contracts', fn ($q) => $q->whereNull('deleted_at')->whereIn('status', ['SIGNED', 'ACTIVE'])->where(fn ($w) => $w->whereNull('starts_at')->orWhereDate('starts_at', '<=', $today))->where(fn ($w) => $w->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today)));
$activeRules = $count('revenue_rules', fn ($q) => $q->whereNull('deleted_at')->where('is_active', 1));
$commercialReady = $currentTerms > 0 && $activeRules > 0;
$metrics['commercial'] = ['current_terms' => $currentTerms, 'active_revenue_rules' => $activeRules];
$add('Finance', 'commercial_terms_and_revenue_rules', $commercialReady ? 'PASS' : 'BLOCKED', 'P1', $commercialReady ? 'Current commercial terms and active Revenue Rules exist.' : 'The Publisher-to-revenue pilot still needs current commercial terms and an active Revenue Rule.', ['current_terms' => $currentTerms, 'active_revenue_rules' => $activeRules]);

$statementCount = $count('publisher_statements');
$metrics['finance'] = ['financial_period_statuses' => $groups('financial_periods', 'status'), 'statements' => $statementCount, 'payment_profiles' => $count('publisher_payment_profiles'), 'payments' => $count('publisher_payments')];
$add('Finance', 'statement_pipeline', $statementCount > 0 ? 'PASS' : 'NOT_CONFIGURED', 'P2', $statementCount > 0 ? 'At least one Publisher statement exists.' : 'No Publisher statement has been produced yet; this remains part of the first real revenue pilot.', ['statement_count' => $statementCount]);

// Advertiser side is not a Publisher-pilot blocker when nothing is active.
$campaignsEnabled = (bool) config('campaigns.advertiser_campaigns_enabled');
$activeCampaigns = $count('campaigns', fn ($q) => $q->whereIn('status', ['APPROVED', 'ACTIVE', 'DELIVERING']));
$scannerPath = trim((string) config('campaigns.malware_scanner_binary'));
$scannerReady = $scannerPath !== '' && is_executable($scannerPath);
$advertiserStatus = $activeCampaigns > 0 ? ($scannerReady ? 'PASS' : 'FAIL') : 'INFO';
$add('Advertiser', 'creative_malware_scanner', $advertiserStatus, $activeCampaigns > 0 && !$scannerReady ? 'P1' : 'P3', $activeCampaigns > 0 ? ($scannerReady ? 'Active advertiser scope has an executable malware scanner.' : 'Active advertiser campaigns exist without an executable malware scanner.') : ($campaignsEnabled ? 'Advertiser campaigns are enabled but none are active; scanner readiness is not a Publisher-pilot blocker.' : 'Advertiser campaigns are disabled.'), ['campaigns_enabled' => $campaignsEnabled, 'active_campaigns' => $activeCampaigns, 'scanner_configured' => $scannerPath !== '', 'scanner_executable' => $scannerReady]);

// Intentional optional controls.
$add('Optional Controls', 'traffic_gate', 'INFO', 'P3', (bool) config('traffic-gate.enabled') ? 'Client Traffic Gate is enabled.' : 'Client Traffic Gate is disabled; it is optional.', ['enabled' => (bool) config('traffic-gate.enabled')]);
$add('Optional Controls', 'registration_turnstile', 'INFO', 'P3', (bool) config('publisher-applications.turnstile.enabled') ? 'Registration Turnstile is enabled.' : 'Registration Turnstile is disabled by current product policy.', ['enabled' => (bool) config('publisher-applications.turnstile.enabled')]);

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
    'privacy' => 'Aggregate-only report. No credentials, secret values, Publisher names, email addresses, payment details, raw private URLs, file contents, or private IDs are emitted.',
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
