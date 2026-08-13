<?php

namespace App\Services\Monetization;

use App\Enums\AdsTxtComplianceStatus;
use App\Enums\ConfigEnvironment;
use App\Enums\DemandIntegrationMode;
use App\Enums\GamHealthStatus;
use App\Enums\MonetizationDependency;
use App\Enums\MonetizationStatus;
use App\Enums\PrebidDeliveryMode;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\BidderSiteMapping;
use App\Models\ConfigVersion;
use App\Models\DailyReport;
use App\Models\DemandSite;
use App\Models\GamConnection;
use App\Models\PrebidBuild;
use App\Models\PrebidSetting;
use App\Models\ReportDimension;
use App\Models\Site;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\Inventory\RuntimePolicyResolver;
use App\Services\Operations\PlatformControlService;
use App\Services\Serving\ResolvedSiteEngineState;
use App\Services\Serving\SiteEngineStateResolver;
use DateTimeInterface;

final class SiteMonetizationReadinessService
{
    public function __construct(
        private readonly SiteEngineStateResolver $engines,
        private readonly PlatformControlService $controls,
        private readonly AdsTxtComplianceService $adsTxt,
        private readonly RuntimePolicyResolver $runtimePolicies,
        private readonly ReportingHealthService $reportingHealth,
    ) {}

    /**
     * Publisher-safe state only. Never add provider identities, account IDs,
     * revenue terms, credentials, internal notes or debug/config payloads here.
     *
     * @return array<string, mixed>
     */
    public function publisher(Site $site): array
    {
        $result = $this->evaluate($site);

        return [
            'site_id' => $site->id,
            'site_name' => $site->display_name,
            'domain' => $site->primary_domain,
            'overall' => $this->publicModule($result['overall']),
            'modules' => collect($result['modules'])
                ->map(fn (array $module): array => $this->publicModule($module))
                ->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function admin(Site $site): array
    {
        return $this->evaluate($site);
    }

    /** @return array<string, mixed> */
    private function evaluate(Site $site): array
    {
        $site->loadMissing(['siteConfig', 'placements.adUnit']);
        $engineState = $this->engines->resolve($site);
        $connection = $engineState->gamConnection;
        $config = $site->siteConfig;
        $runtimePaused = $engineState->paused
            || ($config !== null && $config->status !== 'ACTIVE');

        $production = ConfigVersion::withoutGlobalScopes()
            ->with('deliveryItem')
            ->where('site_id', $site->id)
            ->where('environment', ConfigEnvironment::Production->value)
            ->latest('version')
            ->first();

        $modules = [
            $this->display($site, $engineState, $runtimePaused, $production),
            $this->prebid($site, $engineState),
            $this->native($site, $engineState),
            $this->privacy($site),
            $this->adsTxt($site),
            $this->reporting($site),
            $this->clickGuard($site),
        ];

        return [
            'site_id' => $site->id,
            'site_name' => $site->display_name,
            'domain' => $site->primary_domain,
            'overall' => $this->overall($site, $runtimePaused, $modules),
            'modules' => $modules,
            'diagnostics' => [
                'serving_mode' => $site->serving_mode->value,
                'site_status' => $site->status->value,
                'engines' => $engineState->publicEngineState($connection?->network_code ?: ($engineState->gamRequired ? $site->current_gam_network_code : null)),
                'prebid_control' => [
                    'configured_mode' => $engineState->prebidConfiguredMode->value,
                    'resolved_mode' => $engineState->prebidDeliveryMode->value,
                    'reason' => $engineState->prebidReason,
                ],
                'resolved_gam_connection_id' => $connection?->id,
                'resolved_gam_connection_name' => $connection?->name,
                'gam_network_code' => $connection?->network_code,
                'gam_health' => $connection?->health_status?->value,
                'production_config_version' => $production?->version,
                'production_config_status' => $production?->status?->value,
                'static_delivery_status' => $production?->deliveryItem?->status?->value,
                'static_delivery_attempts' => $production?->deliveryItem?->attempts,
                'last_static_delivery_at' => $this->timestamp($production?->deliveryItem?->delivered_at),
                'ad_units' => $site->placements->filter(fn ($placement) => $placement->adUnit !== null)->count(),
                'placements' => $site->placements->count(),
            ],
        ];
    }

    private function display(Site $site, ResolvedSiteEngineState $engineState, bool $runtimePaused, ?ConfigVersion $production): array
    {
        $connection = $engineState->gamConnection;
        $dependency = $engineState->gamRequired
            ? MonetizationDependency::Critical
            : MonetizationDependency::Optional;

        if (! $engineState->gamRequired) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::NotConfigured, $dependency,
                'GAM is optional for this serving mode and no GAM engine is required.', lastUpdate: $production?->published_at,
                diagnostics: ['serving_mode' => $site->serving_mode->value, 'gam_reason' => $engineState->gamReason]);
        }
        if ($runtimePaused) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::Paused, $dependency,
                'Ad serving is currently paused by a site or master operational control.',
                'Review serving controls before resuming monetization.', null, $production?->published_at,
                ['connection_id' => $connection?->id, 'health' => $connection?->health_status?->value]);
        }
        if ($site->status !== SiteStatus::Active) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::Pending, $dependency,
                'Display monetization will activate after the website lifecycle is active.',
                'Complete website review and activation.', $this->publisherRoute('publisher.sites.show', $site), $site->updated_at,
                ['site_status' => $site->status->value]);
        }
        if ($this->controls->disabledForSite('GAM', $site->id, $connection?->id)) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::Paused, $dependency,
                'The GAM engine is temporarily paused by an operational control.',
                'Review the GAM engine control before resuming GAM delivery.', null, $site->updated_at,
                ['connection_id' => $connection?->id, 'gam_reason' => $engineState->gamReason]);
        }
        if ($connection === null) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::ActionRequired, $dependency,
                'No eligible GAM connection can currently be resolved for this GAM serving mode.',
                'Horus Media must restore an eligible GAM serving connection.', null, $site->updated_at,
                ['legacy_network_code_present' => filled($site->current_gam_network_code)]);
        }
        if (! $connection->is_enabled || in_array($connection->health_status, [GamHealthStatus::Failed, GamHealthStatus::Disabled], true)) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::ActionRequired, $dependency,
                'The display serving connection requires attention.',
                'Horus Media must restore the serving connection.', null, $connection->last_health_check_at,
                $this->gamDiagnostics($connection));
        }
        if (in_array($connection->health_status, [GamHealthStatus::Degraded, GamHealthStatus::Unknown], true)) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::Degraded, $dependency,
                'Display serving is configured, but recent connection health is not fully healthy.',
                'Horus Media is reviewing the serving connection health.', null, $connection->last_health_check_at,
                $this->gamDiagnostics($connection));
        }

        return $this->module('display', 'Display Monetization', MonetizationStatus::Active, $dependency,
            'Display monetization is active.', lastUpdate: $production?->deliveryItem?->delivered_at ?? $connection->last_health_check_at,
            diagnostics: $this->gamDiagnostics($connection));
    }

    private function prebid(Site $site, ResolvedSiteEngineState $engineState): array
    {
        $dependency = $site->serving_mode === ServingMode::HorusDirect && $site->prebid_enabled
            ? MonetizationDependency::Critical
            : MonetizationDependency::Optional;
        $baseDiagnostics = [
            'configured_mode' => $engineState->prebidConfiguredMode->value,
            'resolved_mode' => $engineState->prebidDeliveryMode->value,
            'engine_reason' => $engineState->prebidReason,
        ];

        if (! $site->prebid_enabled) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::NotConfigured, MonetizationDependency::Optional,
                'Header bidding is not enabled for this website.', lastUpdate: $site->updated_at, diagnostics: $baseDiagnostics);
        }
        if (! $engineState->prebidEnabled) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::ActionRequired, $dependency,
                $engineState->prebidReason === 'GAM_BRIDGE_CONNECTION_REQUIRED'
                    ? 'Header bidding is configured for the GAM bridge, but an eligible GAM connection is unavailable.'
                    : 'Header bidding is enabled but cannot currently run.',
                'Horus Media must restore the selected header-bidding delivery mode.', null, $site->updated_at, $baseDiagnostics);
        }

        $mappingCount = BidderSiteMapping::withoutGlobalScopes()->where('site_id', $site->id)->where('enabled', true)->count();
        if ($engineState->prebidDeliveryMode === PrebidDeliveryMode::Standalone) {
            $settings = PrebidSetting::withoutGlobalScopes()->with('build')
                ->where('scope', PrebidSetting::SCOPE_SITE_STANDALONE)
                ->where('site_id', $site->id)->first();
            $diagnostics = $baseDiagnostics + [
                'settings_enabled' => (bool) $settings?->enabled,
                'build' => $settings?->build?->version,
                'mapping_count' => $mappingCount,
            ];
            if (! $settings || ! $settings->enabled || ! $settings->build || $mappingCount === 0) {
                return $this->module('prebid', 'Header Bidding', MonetizationStatus::ActionRequired, $dependency,
                    'Header bidding is enabled for standalone delivery but its runtime profile is incomplete.',
                    'Horus Media must complete the standalone header-bidding profile and bidder mappings.', null, $settings?->updated_at,
                    $diagnostics);
            }

            return $this->module('prebid', 'Header Bidding', MonetizationStatus::Active, $dependency,
                'Header bidding is active with standalone browser delivery.', lastUpdate: $settings->updated_at,
                diagnostics: $diagnostics);
        }

        $connection = $engineState->gamConnection;
        if ($connection === null) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::ActionRequired, $dependency,
                'Header bidding is configured for the GAM bridge, but no eligible GAM connection is available.',
                'Horus Media must restore the GAM bridge connection.', null, $site->updated_at, $baseDiagnostics);
        }
        $settings = PrebidSetting::withoutGlobalScopes()->with('build')
            ->where('scope', PrebidSetting::SCOPE_GAM_CONNECTION)
            ->where('gam_connection_id', $connection->id)->first();
        $diagnostics = $baseDiagnostics + [
            'settings_enabled' => (bool) $settings?->enabled,
            'build' => $settings?->build?->version,
            'gam_connection_id' => $connection->id,
        ];
        if (! $settings || ! $settings->enabled || ! $settings->build || $mappingCount === 0) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::ActionRequired, $dependency,
                'Header bidding is enabled for this website but its managed GAM bridge setup is incomplete.',
                'Horus Media must complete the managed header-bidding setup.', null, $settings?->updated_at,
                $diagnostics);
        }

        return $this->module('prebid', 'Header Bidding', MonetizationStatus::Active, $dependency,
            'Header bidding is active and centrally managed through the GAM bridge.', lastUpdate: $settings->updated_at,
            diagnostics: $diagnostics);
    }

    private function native(Site $site, ResolvedSiteEngineState $engineState): array
    {
        $dependency = in_array($site->serving_mode, [ServingMode::DirectNativeOnly, ServingMode::HorusDirect], true)
            && $site->native_demand_enabled
            ? MonetizationDependency::Critical
            : MonetizationDependency::Optional;
        if (! $site->native_demand_enabled) {
            $critical = $site->serving_mode === ServingMode::DirectNativeOnly;
            return $this->module('native', 'Direct Monetization', $critical ? MonetizationStatus::ActionRequired : MonetizationStatus::NotConfigured,
                $critical ? MonetizationDependency::Critical : $dependency,
                $critical ? 'Direct monetization is required by the current serving mode but is not enabled.' : 'Direct monetization is not configured for this website.',
                $critical ? 'Enable and configure Direct Monetization.' : null, null, $site->updated_at);
        }
        if ($this->controls->disabledForSite('NATIVE_DEMAND', $site->id)
            || $this->controls->disabledForSite('DIRECT_JS', $site->id)) {
            return $this->module('native', 'Direct Monetization', MonetizationStatus::Paused, $dependency,
                'Direct monetization is temporarily paused by an operational control.', lastUpdate: $site->updated_at);
        }

        $mappings = DemandSite::withoutGlobalScopes()
            ->where('site_id', $site->id)->where('is_enabled', true)
            ->with(['account.network', 'placements'])->get();
        $eligible = $mappings->filter(fn ($mapping) => $mapping->approval_status?->value === 'APPROVED'
            && $mapping->account?->is_enabled
            && $mapping->account?->approval_status?->value === 'APPROVED'
            && $mapping->account?->network?->is_enabled);
        $directPlacementCount = $eligible->sum(function ($mapping): int {
            return $mapping->placements->filter(function ($placement) use ($mapping): bool {
                if (! $placement->is_enabled || $placement->approval_status?->value !== 'APPROVED') {
                    return false;
                }
                $mode = $placement->integration_mode ?? $mapping->integration_mode ?? $mapping->account?->integration_mode;

                return ! in_array($mode, [DemandIntegrationMode::GamThirdPartyCreative, DemandIntegrationMode::GamLineItem], true);
            })->count();
        });

        $adminDiagnostics = [
            'engine' => 'DIRECT_JS',
            'engine_reason' => $engineState->directJsReason,
            'mapping_count' => $mappings->count(),
            'eligible_mappings' => $eligible->count(),
            'eligible_direct_placements' => $directPlacementCount,
            'provider_names' => $mappings->pluck('account.network.name')->filter()->unique()->values()->all(),
            'account_names' => $mappings->pluck('account.name')->filter()->unique()->values()->all(),
            'account_ids' => $mappings->pluck('demand_account_id')->values()->all(),
        ];

        $requiresDirect = in_array($site->serving_mode, [ServingMode::HorusDirect, ServingMode::DirectNativeOnly], true);
        if ($eligible->isEmpty() || ($requiresDirect && $directPlacementCount === 0)) {
            return $this->module('native', 'Direct Monetization', MonetizationStatus::ActionRequired, $dependency,
                $requiresDirect
                    ? 'Direct monetization is enabled but no approved direct placement is ready.'
                    : 'Direct monetization is enabled but no approved managed mapping is ready.',
                'Horus Media must complete the Direct Monetization setup.', null, $mappings->max('updated_at'), $adminDiagnostics);
        }

        return $this->module('native', 'Direct Monetization', MonetizationStatus::Active, $dependency,
            'Direct monetization is active.',
            lastUpdate: $eligible->max('last_synced_at') ?? $eligible->max('updated_at'), diagnostics: $adminDiagnostics);
    }

    private function privacy(Site $site): array
    {
        $privacy = $this->runtimePolicies->privacy($site->siteConfig?->privacy_settings);

        return $this->module('privacy', 'Consent / Privacy', MonetizationStatus::Ready, MonetizationDependency::Recommended,
            'Consent and privacy behavior is configured operationally. This status is not a legal certification.',
            lastUpdate: $site->siteConfig?->updated_at ?? $site->updated_at,
            diagnostics: [
                'mode' => data_get($privacy, 'mode'),
                'require_consent_before_ads' => (bool) data_get($privacy, 'requireConsentBeforeAds', false),
                'tcf_version' => data_get($privacy, 'cmp.tcfVersion'),
                'gpp_version' => data_get($privacy, 'cmp.gppVersion'),
            ]);
    }

    private function adsTxt(Site $site): array
    {
        // This reuses persisted Task 3/4 compliance state; summary() does not fetch the live site.
        $summary = $this->adsTxt->summary($site);
        $status = match ($summary['status']) {
            AdsTxtComplianceStatus::Compliant->value => MonetizationStatus::Active,
            AdsTxtComplianceStatus::Stale->value => MonetizationStatus::Degraded,
            AdsTxtComplianceStatus::NotConfigured->value => MonetizationStatus::NotConfigured,
            default => MonetizationStatus::ActionRequired,
        };
        $dependency = ($summary['required_count'] ?? 0) > 0
            ? MonetizationDependency::Critical
            : MonetizationDependency::Recommended;
        $reason = match ($status) {
            MonetizationStatus::Active => 'Ads.txt is compliant with the currently required supply-chain records.',
            MonetizationStatus::Degraded => 'Ads.txt verification is stale and should be refreshed.',
            MonetizationStatus::NotConfigured => 'No ads.txt records are currently required by configured monetization.',
            default => ($summary['missing_count'] ?? 0).' required record(s) are missing and '.($summary['invalid_count'] ?? 0).' are invalid or conflicting.',
        };

        return $this->module('ads_txt', 'Ads.txt / Compliance', $status, $dependency, $reason,
            in_array($status, [MonetizationStatus::ActionRequired, MonetizationStatus::Degraded], true) ? $summary['action'] : null,
            $this->publisherRoute('publisher.ads-txt.index'), $summary['last_checked'],
            ['raw_status' => $summary['status'], 'required_count' => $summary['required_count'], 'correct_count' => $summary['correct_count'], 'missing_count' => $summary['missing_count'], 'invalid_count' => $summary['invalid_count'], 'verification_state' => $summary['verification_state']]);
    }

    private function reporting(Site $site): array
    {
        $health = $this->reportingHealth->forSite($site);
        $status = match ($health['status']) {
            'ACTIVE' => MonetizationStatus::Active,
            'DEGRADED' => MonetizationStatus::Degraded,
            'NOT_CONFIGURED' => MonetizationStatus::NotConfigured,
            default => MonetizationStatus::Pending,
        };

        return $this->module(
            'reporting',
            'Reporting',
            $status,
            MonetizationDependency::Recommended,
            $health['reason'],
            in_array($health['status'], ['DEGRADED', 'PENDING'], true)
                ? 'Horus Media must review the affected aggregated reporting source.'
                : null,
            null,
            $health['last_update'],
            ['sources' => $health['sources']],
        );
    }

    private function clickGuard(Site $site): array
    {
        $guard = $this->runtimePolicies->clickGuard($site->siteConfig?->click_guard_settings);

        return $this->module('click_guard', 'Traffic Protection / Click Guard',
            $guard['enabled'] ? MonetizationStatus::Active : MonetizationStatus::NotConfigured,
            MonetizationDependency::Optional,
            $guard['enabled'] ? 'Traffic protection is enabled.' : 'Traffic protection is currently disabled.',
            lastUpdate: $site->siteConfig?->updated_at ?? $site->updated_at,
            diagnostics: ['enabled' => $guard['enabled']]);
    }

    /** @param array<int, array<string, mixed>> $modules */
    private function overall(Site $site, bool $runtimePaused, array $modules): array
    {
        if ($site->status === SiteStatus::Suspended || $runtimePaused) {
            return $this->module('overall', 'Monetization Overall', MonetizationStatus::Paused, MonetizationDependency::Critical,
                'Monetization is paused and is not considered healthy.',
                'Review the site and operational serving controls before resuming.', $this->publisherRoute('publisher.sites.show', $site), $site->updated_at);
        }
        if ($site->status !== SiteStatus::Active) {
            return $this->module('overall', 'Monetization Overall', MonetizationStatus::Pending, MonetizationDependency::Critical,
                'The website is not active yet; monetization readiness is still pending.',
                'Complete website approval and activation.', $this->publisherRoute('publisher.sites.show', $site), $site->updated_at);
        }

        if ($site->serving_mode === ServingMode::HorusDirect) {
            $engineModules = collect($modules)->whereIn('key', ['display', 'prebid', 'native']);
            $engineAvailable = $engineModules->contains(fn (array $module): bool => in_array(
                $module['status'],
                [MonetizationStatus::Active->value, MonetizationStatus::Degraded->value],
                true,
            ));
            if (! $engineAvailable) {
                return $this->module(
                    'overall',
                    'Monetization Overall',
                    MonetizationStatus::ActionRequired,
                    MonetizationDependency::Critical,
                    'No monetization engine is currently available for this GAM-optional website.',
                    'Enable and repair standalone Header Bidding or Direct Monetization.',
                    $this->publisherRoute('publisher.sites.show', $site),
                    $site->updated_at,
                );
            }
        }

        $critical = collect($modules)->where('dependency', MonetizationDependency::Critical->value);
        $recommended = collect($modules)->where('dependency', MonetizationDependency::Recommended->value);
        $status = match (true) {
            $critical->contains(fn ($module) => $module['status'] === MonetizationStatus::ActionRequired->value) => MonetizationStatus::ActionRequired,
            $critical->contains(fn ($module) => $module['status'] === MonetizationStatus::Paused->value) => MonetizationStatus::Paused,
            $critical->contains(fn ($module) => $module['status'] === MonetizationStatus::Degraded->value) => MonetizationStatus::Degraded,
            $critical->contains(fn ($module) => $module['status'] === MonetizationStatus::Pending->value) => MonetizationStatus::Pending,
            $recommended->contains(fn ($module) => $module['status'] === MonetizationStatus::Degraded->value) => MonetizationStatus::Degraded,
            default => MonetizationStatus::Active,
        };

        return $this->module('overall', 'Monetization Overall', $status, MonetizationDependency::Critical,
            match ($status) {
                MonetizationStatus::Active => 'Critical monetization dependencies are active or ready. Optional integrations do not block this status.',
                MonetizationStatus::ActionRequired => 'A critical monetization dependency requires action.',
                MonetizationStatus::Degraded => 'Monetization is operating with a degraded critical or recommended dependency.',
                MonetizationStatus::Pending => 'A critical monetization dependency is still pending.',
                MonetizationStatus::Paused => 'Monetization is paused.',
                default => 'Monetization readiness is being evaluated.',
            },
            $status === MonetizationStatus::Active ? null : 'Open the module marked for attention below.',
            $this->publisherRoute('publisher.sites.show', $site),
            collect($modules)->pluck('last_update')->filter()->sortDesc()->first());
    }

    /** @return array<string, mixed> */
    private function module(
        string $key,
        string $title,
        MonetizationStatus $status,
        MonetizationDependency $dependency,
        string $reason,
        ?string $actionRequired = null,
        ?string $actionRoute = null,
        mixed $lastUpdate = null,
        array $diagnostics = [],
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'status' => $status->value,
            'dependency' => $dependency->value,
            'reason' => $reason,
            'action_required' => $actionRequired,
            'action_route' => $actionRoute,
            'last_update' => $this->timestamp($lastUpdate),
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return array<string, mixed> */
    private function publicModule(array $module): array
    {
        return [
            'key' => $module['key'],
            'title' => $module['title'],
            'status' => $module['status'],
            'dependency' => $module['dependency'],
            'reason' => $module['reason'],
            'action_required' => $module['action_required'],
            'action_route' => $module['action_route'],
            'last_update' => $module['last_update'],
        ];
    }

    private function publisherRoute(string $name, mixed ...$parameters): ?string
    {
        try {
            return route($name, $parameters);
        } catch (\Throwable) {
            return null;
        }
    }

    private function gamDiagnostics(GamConnection $connection): array
    {
        return [
            'connection_id' => $connection->id,
            'connection_name' => $connection->name,
            'network_code' => $connection->network_code,
            'health' => $connection->health_status->value,
            'last_health_check_at' => $this->timestamp($connection->last_health_check_at),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
