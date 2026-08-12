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
            return $this->module('display', 'GAM / Display Monetization', MonetizationStatus::NotConfigured, $dependency,
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
        if (! $site->prebid_enabled) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::NotConfigured, $dependency,
                'Header bidding is not enabled for this website.', lastUpdate: $site->updated_at);
        }
        if ($this->controls->disabledForSite('PREBID', $site->id)) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::Paused, $dependency,
                'Header bidding is temporarily paused by an operational control.', lastUpdate: $site->updated_at,
                diagnostics: ['delivery_mode' => $engineState->prebidDeliveryMode->value]);
        }

        $mappingCount = BidderSiteMapping::withoutGlobalScopes()
            ->where('site_id', $site->id)->where('enabled', true)
            ->whereHas('account', fn ($query) => $query->where('enabled', true))
            ->whereHas('placementMappings', fn ($query) => $query->where('enabled', true))
            ->count();

        if ($engineState->prebidDeliveryMode === PrebidDeliveryMode::Standalone) {
            $build = PrebidBuild::query()->where('is_active', true)->latest('built_at')->first();
            if (! $build || $mappingCount === 0) {
                return $this->module('prebid', 'Header Bidding', MonetizationStatus::ActionRequired, $dependency,
                    'Standalone Prebid is enabled but its browser build or placement mappings are incomplete.',
                    'Horus Media must complete the standalone Prebid build and bidder mapping setup.', null, $build?->built_at ?? $site->updated_at,
                    ['delivery_mode' => PrebidDeliveryMode::Standalone->value, 'build' => $build?->version, 'enabled_site_mappings' => $mappingCount, 'gam_connection_id' => null]);
            }

            return $this->module('prebid', 'Header Bidding', MonetizationStatus::Ready, $dependency,
                'Standalone Prebid configuration is ready without GAM; direct winning-bid rendering is activated by the dedicated browser-runtime rollout.',
                lastUpdate: $build->built_at ?? $site->updated_at,
                diagnostics: ['delivery_mode' => PrebidDeliveryMode::Standalone->value, 'build' => $build->version, 'enabled_site_mappings' => $mappingCount, 'gam_connection_id' => null]);
        }

        $connection = $engineState->gamConnection;
        if ($connection === null) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::Degraded, $dependency,
                'Header bidding is enabled in GAM bridge mode but display serving is not currently resolvable.', lastUpdate: $site->updated_at,
                diagnostics: ['delivery_mode' => PrebidDeliveryMode::GamBridge->value]);
        }

        // Read-only by design: do not call PrebidManager::settingsFor(), because it can create defaults.
        $settings = PrebidSetting::withoutGlobalScopes()->with('build')
            ->where('gam_connection_id', $connection->id)->first();

        if (! $settings || ! $settings->enabled || ! $settings->build || $mappingCount === 0) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::ActionRequired, $dependency,
                'Header bidding is enabled for this website but its managed GAM bridge setup is incomplete.',
                'Horus Media must complete the managed header-bidding setup.', null, $settings?->updated_at,
                ['delivery_mode' => PrebidDeliveryMode::GamBridge->value, 'settings_enabled' => (bool) $settings?->enabled, 'build' => $settings?->build?->version, 'enabled_site_mappings' => $mappingCount, 'gam_connection_id' => $connection->id]);
        }

        return $this->module('prebid', 'Header Bidding', MonetizationStatus::Active, $dependency,
            'Header bidding is active and centrally managed through the GAM bridge.', lastUpdate: $settings->updated_at,
            diagnostics: ['delivery_mode' => PrebidDeliveryMode::GamBridge->value, 'build' => $settings->build->version, 'enabled_site_mappings' => $mappingCount, 'gam_connection_id' => $connection->id]);
    }

    private function native(Site $site, ResolvedSiteEngineState $engineState): array
    {
        $dependency = in_array($site->serving_mode, [ServingMode::DirectNativeOnly, ServingMode::HorusDirect], true)
            && $site->native_demand_enabled
            ? MonetizationDependency::Critical
            : MonetizationDependency::Optional;
        if (! $site->native_demand_enabled) {
            $critical = $site->serving_mode === ServingMode::DirectNativeOnly;
            return $this->module('native', 'Native Monetization', $critical ? MonetizationStatus::ActionRequired : MonetizationStatus::NotConfigured,
                $critical ? MonetizationDependency::Critical : $dependency,
                $critical ? 'Native monetization is required by the current serving mode but is not enabled.' : 'Direct JS / Native Network monetization is not configured for this website.',
                $critical ? 'Enable and configure Native Monetization.' : null, null, $site->updated_at);
        }
        if ($this->controls->disabledForSite('NATIVE_DEMAND', $site->id)
            || $this->controls->disabledForSite('DIRECT_JS', $site->id)) {
            return $this->module('native', 'Native Monetization', MonetizationStatus::Paused, $dependency,
                'Direct JS / Native Network monetization is temporarily paused by an operational control.', lastUpdate: $site->updated_at);
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
            return $this->module('native', 'Native Monetization', MonetizationStatus::ActionRequired, $dependency,
                $requiresDirect
                    ? 'Direct JS / Native Network monetization is enabled but no approved direct placement is ready.'
                    : 'Native monetization is enabled but no approved managed Native Network mapping is ready.',
                'Horus Media must complete the Native Network setup.', null, $mappings->max('updated_at'), $adminDiagnostics);
        }

        return $this->module('native', 'Native Monetization', MonetizationStatus::Active, $dependency,
            $requiresDirect
                ? 'Direct JS / Native Network monetization is active without requiring GAM.'
                : 'Native Network monetization is active.',
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
        $dimensionIds = ReportDimension::withoutGlobalScopes()->where('site_id', $site->id)->select('id');
        $latest = DailyReport::withoutGlobalScopes()->with('connection')
            ->whereIn('report_dimension_id', $dimensionIds)
            ->latest('report_date')->latest('updated_at')->first();

        if (! $latest) {
            return $this->module('reporting', 'Reporting', MonetizationStatus::Pending, MonetizationDependency::Recommended,
                'No persisted reporting data is available for this website yet.',
                'Reporting will become healthy after the first successful data import.');
        }

        $lastSuccess = $latest->connection?->last_successful_import_at;
        $stale = $latest->report_date->lt(now()->subDays(3)->startOfDay())
            || ($lastSuccess !== null && $lastSuccess->lt(now()->subDays(3)));
        $connectionError = in_array($latest->connection?->status?->value, ['ERROR', 'DISABLED'], true);
        $status = ($stale || $connectionError) ? MonetizationStatus::Degraded : MonetizationStatus::Active;

        return $this->module('reporting', 'Reporting', $status, MonetizationDependency::Recommended,
            $status === MonetizationStatus::Active
                ? 'Reporting data is arriving from persisted imports.'
                : 'Reporting data is delayed or its persisted source health requires attention.',
            $status === MonetizationStatus::Degraded ? 'Horus Media must review reporting source/import health.' : null,
            null, $lastSuccess ?? $latest->updated_at,
            ['connection_id' => $latest->report_source_connection_id, 'connection_name' => $latest->connection?->name, 'connection_status' => $latest->connection?->status?->value, 'last_report_date' => $latest->report_date->toDateString(), 'last_successful_import_at' => $this->timestamp($lastSuccess)]);
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
        unset($module['diagnostics']);
        if (is_string($module['action_route'] ?? null) && str_contains($module['action_route'], '/admin/')) {
            $module['action_route'] = null;
        }

        return $module;
    }

    /** @return array<string, mixed> */
    private function gamDiagnostics(GamConnection $connection): array
    {
        return [
            'connection_id' => $connection->id,
            'connection_name' => $connection->name,
            'connection_type' => $connection->type->value,
            'network_code' => $connection->network_code,
            'health' => $connection->health_status->value,
            'last_health_check_at' => $this->timestamp($connection->last_health_check_at),
            'last_successful_sync_at' => $this->timestamp($connection->last_successful_sync_at),
        ];
    }

    private function publisherRoute(string $name, mixed $parameter = null): ?string
    {
        if (! app('router')->has($name)) {
            return null;
        }

        return route($name, $parameter === null ? [] : $parameter);
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
