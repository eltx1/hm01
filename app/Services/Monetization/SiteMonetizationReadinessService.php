<?php

namespace App\Services\Monetization;

use App\Enums\AdsTxtComplianceStatus;
use App\Enums\ConfigEnvironment;
use App\Enums\GamHealthStatus;
use App\Enums\MonetizationDependency;
use App\Enums\MonetizationStatus;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\BidderSiteMapping;
use App\Models\ConfigVersion;
use App\Models\DailyReport;
use App\Models\DemandSite;
use App\Models\PrebidSetting;
use App\Models\ReportDimension;
use App\Models\Site;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Inventory\RuntimePolicyResolver;
use App\Services\Operations\PlatformControlService;
use Illuminate\Support\Collection;

final class SiteMonetizationReadinessService
{
    public function __construct(
        private readonly GamConnectionResolver $gamConnections,
        private readonly PlatformControlService $controls,
        private readonly AdsTxtComplianceService $adsTxt,
        private readonly RuntimePolicyResolver $runtimePolicies,
    ) {}

    /**
     * Publisher-safe monetization state. This method intentionally never returns
     * provider names, partner/account identifiers, revenue shares, credentials,
     * internal notes, configuration payloads, or debug metadata.
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
            'modules' => collect($result['modules'])->map(fn (array $module) => $this->publicModule($module))->values()->all(),
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
        $connection = $this->gamConnections->resolve($site);
        $config = $site->siteConfig;
        $runtimePaused = $site->serving_mode === ServingMode::Paused
            || $site->status === SiteStatus::Suspended
            || (bool) $config?->immediate_pause
            || ($config && $config->status !== 'ACTIVE')
            || $this->controls->disabledForSite('AD_SERVING', $site->id, $connection?->id);

        $production = ConfigVersion::withoutGlobalScopes()
            ->with('deliveryItem')
            ->where('site_id', $site->id)
            ->where('environment', ConfigEnvironment::Production->value)
            ->latest('version')
            ->first();

        $modules = [
            $this->display($site, $connection, $runtimePaused, $production),
            $this->prebid($site, $connection),
            $this->native($site),
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
                'resolved_gam_connection_id' => $connection?->id,
                'resolved_gam_connection_name' => $connection?->name,
                'gam_network_code' => $connection?->network_code,
                'gam_health' => $connection?->health_status?->value,
                'production_config_version' => $production?->version,
                'production_config_status' => $production?->status?->value,
                'static_delivery_status' => $production?->deliveryItem?->status?->value,
                'static_delivery_attempts' => $production?->deliveryItem?->attempts,
                'last_static_delivery_at' => $production?->deliveryItem?->delivered_at,
                'ad_units' => $site->placements->filter(fn ($placement) => $placement->adUnit !== null)->count(),
                'placements' => $site->placements->count(),
            ],
        ];
    }

    private function display(Site $site, $connection, bool $runtimePaused, ?ConfigVersion $production): array
    {
        $dependency = $site->serving_mode === ServingMode::DirectNativeOnly
            ? MonetizationDependency::Optional
            : MonetizationDependency::Critical;

        if ($site->serving_mode === ServingMode::DirectNativeOnly) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::NotConfigured, $dependency,
                'This website is configured for native-only serving.', null, null, $production?->published_at,
                ['serving_mode' => $site->serving_mode->value]);
        }
        if ($runtimePaused) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::Paused, $dependency,
                'Ad serving is currently paused by an operational or site-level control.',
                'Review serving controls before resuming monetization.', $this->adminRoute('admin.sites.show', $site), $production?->published_at,
                ['connection_id' => $connection?->id, 'health' => $connection?->health_status?->value]);
        }
        if ($site->status !== SiteStatus::Active) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::Pending, $dependency,
                'Display monetization will activate after the website lifecycle is active.',
                'Complete website review and activation.', $this->publisherRoute('publisher.sites.show', $site), $site->updated_at,
                ['site_status' => $site->status->value]);
        }
        if (! $connection) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::ActionRequired, $dependency,
                'No eligible ad-serving connection can currently be resolved.',
                'Horus Media must restore an eligible display serving connection.', $this->adminRoute('admin.gam.connections.index'), $site->updated_at);
        }
        if (! $connection->is_enabled || in_array($connection->health_status, [GamHealthStatus::Failed, GamHealthStatus::Disabled], true)) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::ActionRequired, $dependency,
                'The display serving connection requires attention.',
                'Horus Media must restore the serving connection.', $this->adminRoute('admin.gam.connections.show', $connection), $connection->last_health_check_at,
                ['connection_id' => $connection->id, 'connection_type' => $connection->type->value, 'health' => $connection->health_status->value]);
        }
        if (in_array($connection->health_status, [GamHealthStatus::Degraded, GamHealthStatus::Unknown], true)) {
            return $this->module('display', 'Display Monetization', MonetizationStatus::Degraded, $dependency,
                'Display serving is configured, but recent connection health is not fully healthy.',
                'Horus Media is reviewing the serving connection health.', $this->adminRoute('admin.gam.connections.show', $connection), $connection->last_health_check_at,
                ['connection_id' => $connection->id, 'connection_type' => $connection->type->value, 'network_code' => $connection->network_code, 'health' => $connection->health_status->value]);
        }

        return $this->module('display', 'Display Monetization', MonetizationStatus::Active, $dependency,
            'Display monetization is active.', null, null,
            $production?->deliveryItem?->delivered_at ?? $connection->last_health_check_at,
            ['connection_id' => $connection->id, 'connection_name' => $connection->name, 'connection_type' => $connection->type->value, 'network_code' => $connection->network_code, 'health' => $connection->health_status->value]);
    }

    private function prebid(Site $site, $connection): array
    {
        $dependency = MonetizationDependency::Optional;
        if (! $site->prebid_enabled) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::NotConfigured, $dependency,
                'Header bidding is not enabled for this website.', null, null, $site->updated_at);
        }
        if ($this->controls->disabledForSite('PREBID', $site->id)) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::Paused, $dependency,
                'Header bidding is temporarily paused by an operational control.', null, null, $site->updated_at);
        }
        if (! $connection) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::Degraded, $dependency,
                'Header bidding is enabled but display serving is not currently resolvable.', null, null, $site->updated_at);
        }

        $settings = PrebidSetting::withoutGlobalScopes()->with('build')->where('gam_connection_id', $connection->id)->first();
        $mappings = BidderSiteMapping::withoutGlobalScopes()
            ->where('site_id', $site->id)->where('enabled', true)
            ->whereHas('account', fn ($query) => $query->where('enabled', true))
            ->count();
        if (! $settings || ! $settings->enabled || ! $settings->build || $mappings === 0) {
            return $this->module('prebid', 'Header Bidding', MonetizationStatus::ActionRequired, $dependency,
                'Header bidding is enabled for this website but its managed setup is incomplete.',
                'Horus Media must complete the managed header-bidding setup.', $this->adminRoute('admin.sites.prebid.index', $site), $settings?->updated_at,
                ['settings_enabled' => (bool) $settings?->enabled, 'build' => $settings?->build?->version, 'enabled_site_mappings' => $mappings]);
        }

        return $this->module('prebid', 'Header Bidding', MonetizationStatus::Active, $dependency,
            'Header bidding is active and centrally managed.', null, null, $settings->updated_at,
            ['build' => $settings->build->version, 'enabled_site_mappings' => $mappings, 'gam_connection_id' => $connection->id]);
    }

    private function native(Site $site): array
    {
        $dependency = $site->serving_mode === ServingMode::DirectNativeOnly
            ? MonetizationDependency::Critical
            : MonetizationDependency::Optional;
        if (! $site->native_demand_enabled) {
            $status = $dependency === MonetizationDependency::Critical ? MonetizationStatus::ActionRequired : MonetizationStatus::NotConfigured;
            return $this->module('native', 'Native Monetization', $status, $dependency,
                $dependency === MonetizationDependency::Critical
                    ? 'Native monetization is required by the current serving mode but is not enabled.'
                    : 'Native monetization is not configured for this website.',
                $dependency === MonetizationDependency::Critical ? 'Enable and configure Native Monetization.' : null,
                null, $site->updated_at);
        }
        if ($this->controls->disabledForSite('NATIVE_DEMAND', $site->id)) {
            return $this->module('native', 'Native Monetization', MonetizationStatus::Paused, $dependency,
                'Native monetization is temporarily paused by an operational control.', null, null, $site->updated_at);
        }

        $mappings = DemandSite::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('is_enabled', true)
            ->with(['account.network'])
            ->get();
        $eligible = $mappings->filter(fn ($mapping) => $mapping->approval_status?->value === 'APPROVED'
            && $mapping->account?->is_enabled
            && $mapping->account?->approval_status?->value === 'APPROVED'
            && $mapping->account?->network?->is_enabled);

        if ($eligible->isEmpty()) {
            return $this->module('native', 'Native Monetization', MonetizationStatus::ActionRequired, $dependency,
                'Native monetization is enabled but no approved managed Native Network mapping is ready.',
                'Horus Media must complete the Native Network setup.', $this->adminRoute('admin.sites.demand.index', $site), $mappings->max('updated_at'),
                ['mapping_count' => $mappings->count(), 'provider_names' => $mappings->pluck('account.network.name')->filter()->unique()->values()->all(), 'account_ids' => $mappings->pluck('demand_account_id')->values()->all()]);
        }

        return $this->module('native', 'Native Monetization', MonetizationStatus::Active, $dependency,
            'Native Network monetization is active.', null, null, $eligible->max('last_synced_at') ?? $eligible->max('updated_at'),
            ['eligible_mappings' => $eligible->count(), 'provider_names' => $eligible->pluck('account.network.name')->filter()->unique()->values()->all(), 'account_names' => $eligible->pluck('account.name')->filter()->unique()->values()->all(), 'account_ids' => $eligible->pluck('demand_account_id')->values()->all()]);
    }

    private function privacy(Site $site): array
    {
        $privacy = $this->runtimePolicies->privacy($site->siteConfig?->privacy_settings);
        $requiresConsent = (bool) data_get($privacy, 'requireConsentBeforeAds', false);
        $mode = (string) data_get($privacy, 'mode', 'AUTO');

        return $this->module('privacy', 'Consent / Privacy', MonetizationStatus::Ready, MonetizationDependency::Recommended,
            'Consent and privacy behavior is configured operationally. This status is not a legal certification.',
            null, null, $site->siteConfig?->updated_at ?? $site->updated_at,
            ['mode' => $mode, 'require_consent_before_ads' => $requiresConsent, 'tcf_version' => data_get($privacy, 'cmp.tcfVersion'), 'gpp_version' => data_get($privacy, 'cmp.gppVersion')]);
    }

    private function adsTxt(Site $site): array
    {
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
            default => 'Ads.txt requires attention.',
        };

        return $this->module('ads_txt', 'Ads.txt / Compliance', $status, $dependency, $reason,
            in_array($status, [MonetizationStatus::ActionRequired, MonetizationStatus::Degraded], true) ? $summary['action'] : null,
            $this->publisherRoute('publisher.ads-txt.index'), $summary['last_checked'],
            ['raw_status' => $summary['status'], 'required_count' => $summary['required_count'], 'correct_count' => $summary['correct_count'], 'missing_count' => $summary['missing_count'], 'invalid_count' => $summary['invalid_count'], 'verification_state' => $summary['verification_state']]);
    }

    private function reporting(Site $site): array
    {
        $dimensionIds = ReportDimension::withoutGlobalScopes()->where('site_id', $site->id)->select('id');
        $latest = DailyReport::withoutGlobalScopes()
            ->with('connection')
            ->whereIn('report_dimension_id', $dimensionIds)
            ->latest('report_date')
            ->latest('updated_at')
            ->first();
        if (! $latest) {
            return $this->module('reporting', 'Reporting', MonetizationStatus::Pending, MonetizationDependency::Recommended,
                'No persisted reporting data is available for this website yet.',
                'Reporting will become healthy after the first successful data import.', null, null);
        }

        $lastSuccess = $latest->connection?->last_successful_import_at;
        $stale = $latest->report_date->lt(now()->subDays(3)->startOfDay())
            || ($lastSuccess && $lastSuccess->lt(now()->subDays(3)));
        $connectionError = in_array($latest->connection?->status?->value, ['ERROR', 'DISABLED'], true);
        $status = ($stale || $connectionError) ? MonetizationStatus::Degraded : MonetizationStatus::Active;

        return $this->module('reporting', 'Reporting', $status, MonetizationDependency::Recommended,
            $status === MonetizationStatus::Active
                ? 'Reporting data is arriving from persisted imports.'
                : 'Reporting data is delayed or its persisted source health requires attention.',
            $status === MonetizationStatus::Degraded ? 'Horus Media must review the reporting source/import health.' : null,
            $this->adminRoute('admin.reporting.index'), $lastSuccess ?? $latest->updated_at,
            ['connection_id' => $latest->report_source_connection_id, 'connection_name' => $latest->connection?->name, 'connection_status' => $latest->connection?->status?->value, 'last_report_date' => $latest->report_date->toDateString(), 'last_successful_import_at' => $lastSuccess]);
    }

    private function clickGuard(Site $site): array
    {
        $guard = $this->runtimePolicies->clickGuard($site->siteConfig?->click_guard_settings);
        $status = $guard['enabled'] ? MonetizationStatus::Active : MonetizationStatus::NotConfigured;

        return $this->module('click_guard', 'Traffic Protection / Click Guard', $status, MonetizationDependency::Optional,
            $guard['enabled'] ? 'Traffic protection is enabled.' : 'Traffic protection is currently disabled.',
            null, null, $site->siteConfig?->updated_at ?? $site->updated_at,
            ['enabled' => $guard['enabled']]);
    }

    /** @param array<int, array<string, mixed>> $modules */
    private function overall(Site $site, bool $runtimePaused, array $modules): array
    {
        if ($site->status === SiteStatus::Suspended || $runtimePaused) {
            return $this->module('overall', 'Monetization Overall', MonetizationStatus::Paused, MonetizationDependency::Critical,
                'Monetization is paused and should not be presented as healthy.',
                'Review the site and operational serving controls before resuming.', $this->publisherRoute('publisher.sites.show', $site), $site->updated_at);
        }
        if ($site->status !== SiteStatus::Active) {
            return $this->module('overall', 'Monetization Overall', MonetizationStatus::Pending, MonetizationDependency::Critical,
                'The website is not active yet; monetization readiness is still pending.',
                'Complete the website approval and activation flow.', $this->publisherRoute('publisher.sites.show', $site), $site->updated_at);
        }

        $critical = collect($modules)->where('dependency', MonetizationDependency::Critical->value);
        if ($critical->contains(fn ($module) => $module['status'] === MonetizationStatus::ActionRequired->value)) {
            $status = MonetizationStatus::ActionRequired;
        } elseif ($critical->contains(fn ($module) => $module['status'] === MonetizationStatus::Paused->value)) {
            $status = MonetizationStatus::Paused;
        } elseif ($critical->contains(fn ($module) => $module['status'] === MonetizationStatus::Degraded->value)) {
            $status = MonetizationStatus::Degraded;
        } elseif ($critical->contains(fn ($module) => $module['status'] === MonetizationStatus::Pending->value)) {
            $status = MonetizationStatus::Pending;
        } elseif (collect($modules)->where('dependency', MonetizationDependency::Recommended->value)
            ->contains(fn ($module) => $module['status'] === MonetizationStatus::Degraded->value)) {
            $status = MonetizationStatus::Degraded;
        } else {
            $status = MonetizationStatus::Active;
        }

        return $this->module('overall', 'Monetization Overall', $status, MonetizationDependency::Critical,
            match ($status) {
                MonetizationStatus::Active => 'Critical monetization dependencies are active. Optional integrations do not block this status.',
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
            'last_update' => $lastUpdate?->toIso8601String() ?? (is_string($lastUpdate) ? $lastUpdate : null),
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return array<string, mixed> */
    private function publicModule(array $module): array
    {
        unset($module['diagnostics']);
        if (isset($module['action_route']) && is_string($module['action_route']) && str_contains($module['action_route'], '/admin/')) {
            $module['action_route'] = null;
        }

        return $module;
    }

    private function publisherRoute(string $name, mixed $parameter = null): ?string
    {
        if (! app('router')->has($name)) {
            return null;
        }

        return route($name, $parameter === null ? [] : $parameter);
    }

    private function adminRoute(string $name, mixed $parameter = null): ?string
    {
        if (! app('router')->has($name)) {
            return null;
        }

        return route($name, $parameter === null ? [] : $parameter);
    }
}
