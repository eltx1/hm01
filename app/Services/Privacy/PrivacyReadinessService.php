<?php

namespace App\Services\Privacy;

use App\Enums\ConfigEnvironment;
use App\Enums\PrivacyReadinessStatus;
use App\Models\BidderSiteMapping;
use App\Models\DemandSite;
use App\Models\GoogleCmpEvidence;
use App\Models\PrebidSetting;
use App\Models\PrivacyDiagnosticEvidence;
use App\Models\PrivacyDiagnosticToken;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\RuntimePolicyResolver;
use App\Services\Serving\SiteEngineStateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PrivacyReadinessService
{
    private const TCF_MODULES = ['consentManagementTcf', 'tcfControl'];

    private const GPP_MODULES = ['consentManagementGpp'];

    public function __construct(
        private readonly RuntimePolicyResolver $policies,
        private readonly SiteEngineStateResolver $engines,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{token: string, url: string, expires_at: string, record: PrivacyDiagnosticToken} */
    public function issueDiagnostic(Site $site, ConfigEnvironment $environment, User $actor): array
    {
        $site->loadMissing('domains');
        $raw = Str::random(16).$this->base64Url(random_bytes(32));
        $expiresAt = now()->addMinutes(max(1, min(30, (int) config('privacy.diagnostic_ttl_minutes', 10))));
        $hostnames = $this->siteHostnames($site);

        $record = PrivacyDiagnosticToken::query()->create([
            'site_id' => $site->id,
            'environment' => $environment->value,
            'token_hash' => hash('sha256', $raw),
            'allowed_hostnames' => $hostnames,
            'max_reports' => 1,
            'report_count' => 0,
            'created_by' => $actor->id,
            'expires_at' => $expiresAt,
        ]);

        $this->audit->record(
            'privacy.diagnostic.issued',
            $site->organization_id,
            $actor,
            $site,
            newValues: [
                'diagnostic_id' => $record->id,
                'environment' => $environment->value,
                'allowed_hostnames' => $hostnames,
                'expires_at' => $expiresAt->toIso8601String(),
                'max_reports' => 1,
            ],
        );

        return [
            'token' => $raw,
            'url' => 'https://'.$site->primary_domain.'/?hm_privacy_diagnostic='.rawurlencode($raw),
            'expires_at' => $expiresAt->toIso8601String(),
            'record' => $record,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function acceptDiagnostic(string $rawToken, string $origin, array $payload): PrivacyDiagnosticEvidence
    {
        return DB::transaction(function () use ($rawToken, $origin, $payload): PrivacyDiagnosticEvidence {
            $token = PrivacyDiagnosticToken::query()
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            if (! $token || $token->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => 'The privacy diagnostic token is invalid or expired.']);
            }
            if ($token->report_count >= $token->max_reports) {
                throw ValidationException::withMessages(['token' => 'The privacy diagnostic token has already been consumed.']);
            }

            $originHost = $this->originHostname($origin);
            $reportedHost = $this->normalizeHostname((string) ($payload['hostname'] ?? ''));
            if (! $originHost || $reportedHost !== $originHost || ! $this->hostAllowed($originHost, (array) $token->allowed_hostnames)) {
                throw ValidationException::withMessages(['hostname' => 'The diagnostic origin is not authorized for this token.']);
            }

            $site = Site::withoutGlobalScopes()->with('siteConfig')->findOrFail($token->site_id);
            $observedAt = now()->utc();
            $status = $this->diagnosticResultStatus($site, $payload);
            $sanitized = [
                'loader_version' => (string) $payload['loaderVersion'],
                'config_version' => isset($payload['configVersion']) ? (int) $payload['configVersion'] : null,
                'hostname' => $reportedHost,
                'tcf_api_detected' => (bool) data_get($payload, 'tcf.detected'),
                'tcf_api_responded' => (bool) data_get($payload, 'tcf.responded'),
                'tcf_cmp_id' => data_get($payload, 'tcf.cmpId') !== null ? (int) data_get($payload, 'tcf.cmpId') : null,
                'tcf_event_status' => data_get($payload, 'tcf.eventStatus'),
                'gpp_api_detected' => (bool) data_get($payload, 'gpp.detected'),
                'gpp_api_responded' => (bool) data_get($payload, 'gpp.responded'),
                'gpp_applicable_sections' => array_values(array_unique(array_map('intval', (array) data_get($payload, 'gpp.applicableSections', [])))),
                'gpc_detected' => (bool) $payload['gpcDetected'],
                'configured_timeout_action' => strtoupper((string) $payload['configuredTimeoutAction']),
                'prebid_modules' => array_values(array_unique(array_map('strval', (array) data_get($payload, 'prebid.modulesPresent', [])))),
                'prebid_consent_configured' => (bool) data_get($payload, 'prebid.consentConfigured'),
                'prebid_storage_control_configured' => (bool) data_get($payload, 'prebid.storageControlConfigured'),
                'prebid_activity_controls_configured' => (bool) data_get($payload, 'prebid.activityControlsConfigured'),
                'privacy_gate_respected' => (bool) $payload['privacyGateRespected'],
            ];
            $resultHash = hash('sha256', json_encode([
                'site_id' => $site->id,
                'environment' => $token->environment,
                'status' => $status->value,
                'observed_at' => $observedAt->toIso8601String(),
                'result' => $sanitized,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $evidence = PrivacyDiagnosticEvidence::withoutGlobalScopes()->create($sanitized + [
                'organization_id' => $site->organization_id,
                'site_id' => $site->id,
                'privacy_diagnostic_token_id' => $token->id,
                'environment' => $token->environment,
                'result_status' => $status->value,
                'observed_at' => $observedAt,
                'result_hash' => $resultHash,
            ]);

            $token->forceFill([
                'report_count' => $token->report_count + 1,
                'last_used_at' => $observedAt,
                'completed_at' => $token->report_count + 1 >= $token->max_reports ? $observedAt : null,
            ])->save();

            return $evidence;
        });
    }

    /** @param array<string, mixed> $data */
    public function recordGoogleCmpEvidence(Site $site, ConfigEnvironment $environment, array $data, User $actor): GoogleCmpEvidence
    {
        $evidence = GoogleCmpEvidence::withoutGlobalScopes()->updateOrCreate(
            ['site_id' => $site->id, 'environment' => $environment->value],
            [
                'organization_id' => $site->organization_id,
                'cmp_name' => trim((string) $data['cmp_name']),
                'tcf_cmp_id' => (int) $data['tcf_cmp_id'],
                'platform' => strtoupper((string) $data['platform']),
                'last_verification_date' => $data['last_verification_date'],
                'operator_verification_status' => strtoupper((string) $data['operator_verification_status']),
                'verified_by' => $actor->id,
            ],
        );

        $this->audit->record(
            'privacy.google_cmp_evidence.recorded',
            $site->organization_id,
            $actor,
            $site,
            newValues: $evidence->only(['environment', 'cmp_name', 'tcf_cmp_id', 'platform', 'last_verification_date', 'operator_verification_status']),
        );

        return $evidence;
    }

    /** @return array<string, mixed> */
    public function admin(Site $site, ConfigEnvironment $environment = ConfigEnvironment::Production): array
    {
        return $this->evaluate($site, $environment, false);
    }

    /** @return array<string, mixed> */
    public function publisher(Site $site, ConfigEnvironment $environment = ConfigEnvironment::Production): array
    {
        return $this->evaluate($site, $environment, true);
    }

    /** @return array<string, mixed> */
    private function evaluate(Site $site, ConfigEnvironment $environment, bool $publisherSafe): array
    {
        $site->loadMissing(['siteConfig', 'domains']);
        $privacy = $this->policies->privacy($site->siteConfig?->privacy_settings);
        $tcfRequired = $this->required($privacy, 'tcf');
        $gppRequired = $this->required($privacy, 'gpp');
        $googleRequired = (bool) data_get($privacy, 'cmp.googleCmpEvidenceRequired', false);
        $findings = [];

        $configuration = $this->configurationSection($privacy, $findings);
        $latest = PrivacyDiagnosticEvidence::withoutGlobalScopes()
            ->where('site_id', $site->id)->where('environment', $environment->value)
            ->latest('observed_at')->first();
        $live = $this->liveSection($latest, $findings);
        $tcf = $this->frameworkSection('TCF', $tcfRequired, $latest, $findings);
        $gpp = $this->frameworkSection('GPP', $gppRequired, $latest, $findings);
        $gpc = $this->gpcSection($privacy, $latest, $findings);
        $prebid = $this->prebidSection($site, $tcfRequired, $gppRequired, $findings);
        $google = $this->googleCmpSection($site, $environment, $googleRequired, $findings);
        $providers = $this->providerSection($site, $tcfRequired, $gppRequired, $findings, $publisherSafe);

        $sections = compact('configuration', 'live', 'tcf', 'gpp', 'gpc', 'prebid', 'google', 'providers');
        $statuses = collect($sections)->pluck('status');
        $overallStatus = match (true) {
            $statuses->contains(PrivacyReadinessStatus::Blocked->value) => PrivacyReadinessStatus::Blocked,
            $statuses->contains(PrivacyReadinessStatus::Stale->value) => PrivacyReadinessStatus::Stale,
            $statuses->contains(PrivacyReadinessStatus::Warning->value) => PrivacyReadinessStatus::Warning,
            $statuses->contains(PrivacyReadinessStatus::Unknown->value) => PrivacyReadinessStatus::Warning,
            default => PrivacyReadinessStatus::Ready,
        };
        $liveVerified = $latest !== null && $live['status'] === PrivacyReadinessStatus::Ready->value;

        return [
            'overall' => [
                'status' => $overallStatus->value,
                'configuration_state' => $configuration['status'] === PrivacyReadinessStatus::Blocked->value ? 'CONFIGURATION_BLOCKED' : 'CONFIGURED',
                'live_state' => $liveVerified ? 'LIVE_VERIFIED' : ($live['status'] === PrivacyReadinessStatus::Stale->value ? 'LIVE_EVIDENCE_STALE' : 'NOT_LIVE_VERIFIED'),
                'legal_certification' => false,
            ],
            'sections' => $sections,
            'findings' => $publisherSafe
                ? collect($findings)->map(fn (array $finding) => collect($finding)->only(['code', 'status', 'message'])->all())->values()->all()
                : $findings,
            'last_verified' => $latest?->observed_at?->toIso8601String(),
            'environment' => $environment->value,
        ];
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function configurationSection(array $privacy, array &$findings): array
    {
        $blocked = false;
        $warning = false;
        $mode = strtoupper((string) data_get($privacy, 'mode', 'AUTO'));
        $timeoutAction = strtoupper((string) data_get($privacy, 'cmp.actionOnTimeout', 'LIMITED_ADS'));
        $timeout = (int) data_get($privacy, 'cmp.timeoutMs', 1200);
        if (! in_array($mode, ['AUTO', 'STRICT'], true)) {
            $blocked = true;
            $findings[] = $this->finding('PRIVACY_MODE_INVALID', PrivacyReadinessStatus::Blocked, 'Privacy mode must be AUTO or STRICT.');
        }
        if (! in_array($timeoutAction, ['LIMITED_ADS', 'BLOCK_ADS', 'PROCEED'], true) || $timeout < 100 || $timeout > 10000) {
            $blocked = true;
            $findings[] = $this->finding('PRIVACY_TIMEOUT_CONFIGURATION_INVALID', PrivacyReadinessStatus::Blocked, 'CMP timeout or timeout action is outside the supported runtime contract.');
        } elseif ($timeoutAction === 'BLOCK_ADS') {
            $warning = true;
            $findings[] = $this->finding('CONSENT_TIMEOUT_BLOCKING', PrivacyReadinessStatus::Warning, 'The configured timeout action intentionally blocks advertising when the CMP does not complete.');
        }
        if (! $blocked) {
            $findings[] = $this->finding('PRIVACY_CONFIGURATION_VALID', PrivacyReadinessStatus::Ready, 'The runtime privacy configuration is structurally valid; this is not a legal certification.');
        }

        return $this->section($blocked ? PrivacyReadinessStatus::Blocked : ($warning ? PrivacyReadinessStatus::Warning : PrivacyReadinessStatus::Ready), [
            'mode' => $mode,
            'require_consent_before_ads' => (bool) data_get($privacy, 'requireConsentBeforeAds', true),
            'cmp_timeout_ms' => $timeout,
            'timeout_action' => $timeoutAction,
            'tcf_version' => data_get($privacy, 'cmp.tcfVersion'),
            'gpp_version' => data_get($privacy, 'cmp.gppVersion'),
            'gpc_handling' => (bool) data_get($privacy, 'signals.gpc', true),
            'coppa' => (bool) data_get($privacy, 'signals.coppa', false),
            'under_age_of_consent' => (bool) data_get($privacy, 'signals.underAgeOfConsent', false),
        ]);
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function liveSection(?PrivacyDiagnosticEvidence $evidence, array &$findings): array
    {
        if (! $evidence) {
            $findings[] = $this->finding('LIVE_PRIVACY_PROBE_MISSING', PrivacyReadinessStatus::Unknown, 'Privacy is configured but has not been live verified.');

            return $this->section(PrivacyReadinessStatus::Unknown, ['observed_at' => null]);
        }
        if ($evidence->observed_at->lt(now()->subDays(max(1, (int) config('privacy.probe_stale_days', 30))))) {
            $findings[] = $this->finding('PRIVACY_PROBE_STALE', PrivacyReadinessStatus::Stale, 'The latest live privacy probe is older than the configured evidence window.');

            return $this->section(PrivacyReadinessStatus::Stale, $this->evidenceDetails($evidence));
        }

        $status = PrivacyReadinessStatus::tryFrom($evidence->result_status) ?? PrivacyReadinessStatus::Unknown;
        if ($status === PrivacyReadinessStatus::Ready) {
            $findings[] = $this->finding('LIVE_PROBE_VERIFIED', PrivacyReadinessStatus::Ready, 'A current one-shot live privacy diagnostic completed successfully.');
        }

        return $this->section($status, $this->evidenceDetails($evidence));
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function frameworkSection(string $framework, bool $required, ?PrivacyDiagnosticEvidence $evidence, array &$findings): array
    {
        $prefix = strtolower($framework);
        if (! $required) {
            return $this->section(PrivacyReadinessStatus::NotApplicable, ['required' => false]);
        }
        if (! $evidence) {
            return $this->section(PrivacyReadinessStatus::Unknown, ['required' => true, 'detected' => null, 'responded' => null]);
        }
        $detected = (bool) $evidence->{$prefix.'_api_detected'};
        $responded = (bool) $evidence->{$prefix.'_api_responded'};
        if (! $detected) {
            $findings[] = $this->finding($framework.'_REQUIRED_BUT_API_MISSING', PrivacyReadinessStatus::Blocked, $framework.' is required by configuration but its browser API was not detected.');

            return $this->section(PrivacyReadinessStatus::Blocked, ['required' => true, 'detected' => false, 'responded' => false]);
        }
        if (! $responded) {
            $findings[] = $this->finding($framework.'_API_NOT_RESPONDING', PrivacyReadinessStatus::Blocked, $framework.' API was detected but did not respond during the bounded diagnostic.');

            return $this->section(PrivacyReadinessStatus::Blocked, ['required' => true, 'detected' => true, 'responded' => false]);
        }

        return $this->section(PrivacyReadinessStatus::Ready, [
            'required' => true,
            'detected' => true,
            'responded' => true,
            ...($framework === 'TCF' ? ['cmp_id' => $evidence->tcf_cmp_id, 'event_status' => $evidence->tcf_event_status] : ['applicable_sections' => $evidence->gpp_applicable_sections ?? []]),
        ]);
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function gpcSection(array $privacy, ?PrivacyDiagnosticEvidence $evidence, array &$findings): array
    {
        if (! (bool) data_get($privacy, 'signals.gpc', true)) {
            $findings[] = $this->finding('GPC_HANDLING_DISABLED', PrivacyReadinessStatus::Warning, 'Runtime GPC handling is disabled in site configuration.');

            return $this->section(PrivacyReadinessStatus::Warning, ['configured' => false, 'detected' => $evidence?->gpc_detected]);
        }

        return $this->section($evidence ? PrivacyReadinessStatus::Ready : PrivacyReadinessStatus::Unknown, ['configured' => true, 'detected' => $evidence?->gpc_detected]);
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function prebidSection(Site $site, bool $tcfRequired, bool $gppRequired, array &$findings): array
    {
        if (! $site->prebid_enabled) {
            return $this->section(PrivacyReadinessStatus::NotApplicable, ['enabled' => false]);
        }
        $state = $this->engines->resolve($site);
        $settings = $state->prebidDeliveryMode->value === 'STANDALONE'
            ? PrebidSetting::withoutGlobalScopes()->with('build')->where('scope', PrebidSetting::SCOPE_SITE_STANDALONE)->where('site_id', $site->id)->first()
            : PrebidSetting::withoutGlobalScopes()->with('build')->where('scope', PrebidSetting::SCOPE_GAM_CONNECTION)->where('gam_connection_id', $state->gamConnection?->id)->first();
        $modules = array_values((array) $settings?->build?->modules);
        $consent = (array) ($settings?->consent_behavior ?? []);
        $blocked = false;
        $warning = false;
        foreach ([['required' => $tcfRequired, 'modules' => self::TCF_MODULES, 'config' => 'gdpr', 'code' => 'TCF'], ['required' => $gppRequired, 'modules' => self::GPP_MODULES, 'config' => 'gpp', 'code' => 'GPP']] as $requirement) {
            if (! $requirement['required']) {
                continue;
            }
            foreach ($requirement['modules'] as $module) {
                if (! in_array($module, $modules, true)) {
                    $blocked = true;
                    $findings[] = $this->finding('PREBID_'.$requirement['code'].'_MODULE_MISSING', PrivacyReadinessStatus::Blocked, 'The active Prebid build is missing '.$module.'.');
                }
            }
            if (! is_array($consent[$requirement['config']] ?? null)) {
                $blocked = true;
                $findings[] = $this->finding('PREBID_'.$requirement['code'].'_CONFIG_MISSING', PrivacyReadinessStatus::Blocked, 'The active Prebid profile is missing '.$requirement['config'].' consent configuration.');
            }
        }
        if (! in_array('storageControl', $modules, true)) {
            $warning = true;
            $findings[] = $this->finding('PREBID_STORAGE_CONTROL_MODULE_MISSING', PrivacyReadinessStatus::Warning, 'The active Prebid build does not include storageControl.');
        }

        return $this->section($blocked ? PrivacyReadinessStatus::Blocked : ($warning ? PrivacyReadinessStatus::Warning : PrivacyReadinessStatus::Ready), [
            'enabled' => true,
            'delivery_mode' => $state->prebidDeliveryMode->value,
            'build_version' => $settings?->build?->version,
            'modules' => $modules,
            'consent_configured' => $consent !== [],
            'storage_control_module' => in_array('storageControl', $modules, true),
            'activity_controls_configured' => true,
        ]);
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function googleCmpSection(Site $site, ConfigEnvironment $environment, bool $required, array &$findings): array
    {
        if (! $required) {
            return $this->section(PrivacyReadinessStatus::NotApplicable, ['evidence_status' => 'NOT_APPLICABLE']);
        }
        $evidence = GoogleCmpEvidence::withoutGlobalScopes()->where('site_id', $site->id)->where('environment', $environment->value)->first();
        if (! $evidence) {
            $findings[] = $this->finding('GOOGLE_CMP_EVIDENCE_MISSING', PrivacyReadinessStatus::Blocked, 'Google CMP evidence is explicitly required for this runtime policy but has not been recorded.');

            return $this->section(PrivacyReadinessStatus::Blocked, ['evidence_status' => 'NOT_VERIFIED']);
        }
        if ($evidence->operator_verification_status !== 'VERIFIED') {
            $findings[] = $this->finding('GOOGLE_CMP_EVIDENCE_NOT_VERIFIED', PrivacyReadinessStatus::Blocked, 'Google CMP operator evidence is present but has not been marked VERIFIED by an authorized Admin.');

            return $this->section(PrivacyReadinessStatus::Blocked, $this->googleCmpDetails($evidence, 'NOT_VERIFIED'));
        }
        if ($evidence->last_verification_date->lt(today()->subDays(max(1, (int) config('privacy.google_cmp_evidence_stale_days', 90))))) {
            $findings[] = $this->finding('GOOGLE_CMP_EVIDENCE_STALE', PrivacyReadinessStatus::Stale, 'The operator verification date for Google CMP evidence is stale.');

            return $this->section(PrivacyReadinessStatus::Stale, $this->googleCmpDetails($evidence, 'STALE'));
        }

        return $this->section(PrivacyReadinessStatus::Ready, $this->googleCmpDetails($evidence, 'VERIFIED'));
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function providerSection(Site $site, bool $tcfRequired, bool $gppRequired, array &$findings, bool $publisherSafe): array
    {
        $providers = collect();
        if ($site->prebid_enabled) {
            $providers = $providers->merge(BidderSiteMapping::withoutGlobalScopes()->where('site_id', $site->id)->where('enabled', true)
                ->with('account.bidder')->get()->map(fn ($mapping) => ['type' => 'PREBID', 'name' => $mapping->account?->bidder?->display_name, 'capabilities' => (array) $mapping->account?->bidder?->privacy_capabilities]));
        }
        if ($site->native_demand_enabled) {
            $providers = $providers->merge(DemandSite::withoutGlobalScopes()->where('site_id', $site->id)->where('is_enabled', true)
                ->with('account.network')->get()->map(fn ($mapping) => ['type' => 'DIRECT_JS', 'name' => $mapping->account?->network?->name, 'capabilities' => (array) $mapping->account?->network?->privacy_capabilities]));
        }
        if ($providers->isEmpty()) {
            return $this->section(PrivacyReadinessStatus::NotApplicable, ['provider_count' => 0]);
        }

        $rows = $providers->filter(fn ($provider) => filled($provider['name']))->unique(fn ($provider) => $provider['type'].'|'.$provider['name'])->map(function (array $provider) use ($tcfRequired, $gppRequired, &$findings): array {
            $checks = [];
            foreach ([['key' => 'tcf', 'required' => $tcfRequired], ['key' => 'gpp', 'required' => $gppRequired]] as $requirement) {
                $state = $this->capabilityState($provider['capabilities'], $requirement['key']);
                $checks[$requirement['key']] = $state;
                if ($requirement['required'] && $state === 'UNKNOWN') {
                    $findings[] = $this->finding('PROVIDER_'.strtoupper($requirement['key']).'_CAPABILITY_UNKNOWN', PrivacyReadinessStatus::Warning, 'Provider privacy support remains UNKNOWN until operator or current official evidence is recorded.', ['provider' => $provider['name'], 'provider_type' => $provider['type']]);
                } elseif ($requirement['required'] && $state === 'NOT_SUPPORTED') {
                    $findings[] = $this->finding('PROVIDER_'.strtoupper($requirement['key']).'_NOT_SUPPORTED', PrivacyReadinessStatus::Blocked, 'A provider is explicitly recorded as not supporting a privacy framework required by this site.', ['provider' => $provider['name'], 'provider_type' => $provider['type']]);
                }
            }

            return ['type' => $provider['type'], 'name' => $provider['name'], 'capabilities' => $checks + [
                'gpc' => $this->capabilityState($provider['capabilities'], 'gpc'),
                'consent_before_request' => strtoupper((string) data_get($provider['capabilities'], 'consent_before_request', 'UNKNOWN')),
                'storage' => strtoupper((string) data_get($provider['capabilities'], 'storage', 'UNKNOWN')),
                'user_sync' => strtoupper((string) data_get($provider['capabilities'], 'user_sync', 'UNKNOWN')),
            ]];
        })->values();
        $hasUnknown = $rows->contains(fn (array $row) => in_array('UNKNOWN', $row['capabilities'], true));
        $hasUnsupportedRequired = $rows->contains(fn (array $row): bool => ($tcfRequired && $row['capabilities']['tcf'] === 'NOT_SUPPORTED')
            || ($gppRequired && $row['capabilities']['gpp'] === 'NOT_SUPPORTED'));

        return $this->section($hasUnsupportedRequired ? PrivacyReadinessStatus::Blocked : ($hasUnknown ? PrivacyReadinessStatus::Warning : PrivacyReadinessStatus::Ready), $publisherSafe
            ? ['provider_count' => $rows->count(), 'unknown_capability_count' => $rows->filter(fn ($row) => in_array('UNKNOWN', $row['capabilities'], true))->count()]
            : ['provider_count' => $rows->count(), 'providers' => $rows->all()]);
    }

    /** @param array<string, mixed> $payload */
    private function diagnosticResultStatus(Site $site, array $payload): PrivacyReadinessStatus
    {
        $privacy = $this->policies->privacy($site->siteConfig?->privacy_settings);
        if (! (bool) $payload['privacyGateRespected']) {
            return PrivacyReadinessStatus::Blocked;
        }
        foreach ([['name' => 'tcf', 'required' => $this->required($privacy, 'tcf')], ['name' => 'gpp', 'required' => $this->required($privacy, 'gpp')]] as $framework) {
            if ($framework['required'] && (! data_get($payload, $framework['name'].'.detected') || ! data_get($payload, $framework['name'].'.responded'))) {
                return PrivacyReadinessStatus::Blocked;
            }
            if (data_get($payload, $framework['name'].'.detected') && ! data_get($payload, $framework['name'].'.responded')) {
                return PrivacyReadinessStatus::Warning;
            }
        }

        return PrivacyReadinessStatus::Ready;
    }

    private function required(array $privacy, string $framework): bool
    {
        return (bool) data_get($privacy, 'cmp.'.$framework.'Required', data_get($privacy, $framework.'.required', false));
    }

    /** @return array<string, mixed> */
    private function evidenceDetails(PrivacyDiagnosticEvidence $evidence): array
    {
        return [
            'result_status' => $evidence->result_status,
            'loader_version' => $evidence->loader_version,
            'config_version' => $evidence->config_version,
            'hostname' => $evidence->hostname,
            'observed_at' => $evidence->observed_at->toIso8601String(),
            'result_hash' => $evidence->result_hash,
            'privacy_gate_respected' => $evidence->privacy_gate_respected,
        ];
    }

    /** @return array<string, mixed> */
    private function googleCmpDetails(GoogleCmpEvidence $evidence, string $status): array
    {
        return [
            'evidence_status' => $status,
            'cmp_name' => $evidence->cmp_name,
            'tcf_cmp_id' => $evidence->tcf_cmp_id,
            'platform' => $evidence->platform,
            'last_verification_date' => $evidence->last_verification_date->toDateString(),
        ];
    }

    /** @return array{status: string, details: array<string, mixed>} */
    private function section(PrivacyReadinessStatus $status, array $details): array
    {
        return ['status' => $status->value, 'details' => $details];
    }

    /** @return array<string, mixed> */
    private function finding(string $code, PrivacyReadinessStatus $status, string $message, array $context = []): array
    {
        return ['code' => $code, 'status' => $status->value, 'message' => $message, 'context' => $context];
    }

    private function capabilityState(array $capabilities, string $key): string
    {
        $value = strtoupper((string) data_get($capabilities, $key, 'UNKNOWN'));

        return in_array($value, ['SUPPORTED', 'NOT_SUPPORTED'], true) ? $value : 'UNKNOWN';
    }

    /** @return array<int, string> */
    private function siteHostnames(Site $site): array
    {
        return collect([$site->primary_domain])->merge($site->domains->pluck('domain'))
            ->map(fn ($hostname) => $this->normalizeHostname((string) $hostname))->filter()->unique()->values()->all();
    }

    private function originHostname(string $origin): ?string
    {
        $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        $port = parse_url($origin, PHP_URL_PORT);
        $host = $this->normalizeHostname((string) parse_url($origin, PHP_URL_HOST));

        return $scheme === 'https' && ($port === null || $port === 443) && $host !== '' ? $host : null;
    }

    private function normalizeHostname(string $hostname): string
    {
        return strtolower(rtrim(preg_replace('#^https?://#i', '', trim($hostname)), './'));
    }

    /** @param array<int, string> $allowed */
    private function hostAllowed(string $hostname, array $allowed): bool
    {
        return collect($allowed)->contains(function ($candidate) use ($hostname): bool {
            $candidate = $this->normalizeHostname((string) $candidate);

            return str_starts_with($candidate, '*.')
                ? str_ends_with($hostname, substr($candidate, 1)) && $hostname !== substr($candidate, 2)
                : $hostname === $candidate;
        });
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
