<?php

namespace App\Services\Inventory;

use App\Enums\ConfigEnvironment;
use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Enums\PrebidDeliveryMode;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\LoaderRelease;
use App\Models\Placement;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Models\TagVersion;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Operations\PlatformControlService;
use App\Services\Prebid\PrebidConfigurationBuilder;
use App\Services\Serving\SiteEngineStateResolver;
use App\Services\SupplyChain\SupplyChainInvariantService;
use App\Services\SupplyChain\SupplyChainObjectValidator;

final class SiteConfigurationBuilder
{
    public function __construct(
        private readonly SiteEngineStateResolver $engines,
        private readonly PrebidConfigurationBuilder $prebid,
        private readonly DemandConfigurationBuilder $demand,
        private readonly PlatformControlService $controls,
        private readonly SupplyChainInvariantService $supplyChain,
        private readonly SupplyChainObjectValidator $supplyChainValidator,
    ) {
    }

    public function build(Site $site, ConfigEnvironment $environment, int $version): array
    {
        $site->loadMissing([
            'domains', 'placements.adUnit', 'placements.adFormat', 'placements.sizes', 'placements.targeting',
            'targeting', 'siteConfig.loaderRelease', 'siteConfig.tagVersion',
        ]);

        $config = $site->siteConfig ?? SiteConfig::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'cache_ttl_seconds' => config('horus.config_cache_ttl_seconds', 60),
        ]);
        if (! $site->relationLoaded('siteConfig') || $site->siteConfig === null) {
            $site->setRelation('siteConfig', $config);
        }

        $engineState = $this->engines->resolve($site);
        $connection = $engineState->gamConnection;
        // Preserve the legacy network-code fallback for the three established
        // GAM modes only. HORUS_DIRECT never receives a synthetic/stale GAM ID.
        $networkCode = $engineState->gamRequired
            ? ($connection?->network_code ?: $site->current_gam_network_code)
            : null;
        $loader = $config->loaderRelease ?: LoaderRelease::query()->where('is_active', true)->latest('published_at')->first();
        $tag = $config->tagVersion ?: TagVersion::query()->where('is_active', true)->latest('published_at')->first();

        $adServingDisabled = $this->controls->disabledForSite('AD_SERVING', $site->id, $connection?->id);
        $gamDisabled = $this->controls->disabledForSite('GAM', $site->id, $connection?->id);
        $prebidDisabled = $this->controls->disabledForSite('PREBID', $site->id);
        $legacyNativeDisabled = $this->controls->disabledForSite('NATIVE_DEMAND', $site->id);
        $directJsOnlyDisabled = $this->controls->disabledForSite('DIRECT_JS', $site->id);
        $directJsDisabled = $directJsOnlyDisabled || $legacyNativeDisabled;

        $active = $site->status === SiteStatus::Active
            && ! $adServingDisabled && $config->status === 'ACTIVE'
            && ! $config->immediate_pause
            && $site->serving_mode !== ServingMode::Paused;

        $prebid = $this->prebid->build($site, $connection, $engineState->prebidDeliveryMode);
        $native = $this->demand->build($site);
        $publicNative = $this->filterNativeDemand($native, $legacyNativeDisabled, $directJsOnlyDisabled);

        // Legacy Prebid flags remain bridge-only so existing schema-v2 consumers
        // never interpret standalone delivery as GAM header bidding.
        $legacyPrebidEnabled = $engineState->prebidDeliveryMode === PrebidDeliveryMode::GamBridge
            && $engineState->prebidEnabled
            && ! $prebidDisabled
            && (bool) $prebid['enabled'];
        $legacyNativeEnabled = (bool) $publicNative['enabled'];
        $effectivePrebidEnabled = $active && $engineState->prebidEnabled && (bool) $prebid['enabled'];
        $effectiveDirectJsEnabled = $active && $engineState->directJsEnabled && $this->hasDirectJsCandidate($publicNative);
        $effectiveGamEnabled = $active && $engineState->gamEnabled && ! $gamDisabled && filled($networkCode);

        $enginePayload = $engineState->publicEngineState($networkCode);
        $enginePayload['gam']['enabled'] = $effectiveGamEnabled;
        $enginePayload['prebid']['enabled'] = $effectivePrebidEnabled;
        if ($engineState->prebidEnabled && ! $prebid['enabled']) {
            $enginePayload['prebid']['reason'] = 'PREBID_CONFIGURATION_INCOMPLETE';
        }
        $enginePayload['directJs']['enabled'] = $effectiveDirectJsEnabled;
        if ($engineState->directJsEnabled && ! $this->hasDirectJsCandidate($publicNative)) {
            $enginePayload['directJs']['reason'] = 'DIRECT_JS_CONFIGURATION_INCOMPLETE';
        }

        $standalonePrebidCodes = $effectivePrebidEnabled
            && $engineState->prebidDeliveryMode === PrebidDeliveryMode::Standalone
            ? collect($prebid['adUnits'])->pluck('code')->filter()->values()->all()
            : [];

        $schain = $this->supplyChain->schainForSite($site);
        $publicSchain = [
            'complete' => $schain['complete'],
            'ver' => $schain['ver'],
            'nodes' => $schain['nodes'],
        ];
        if ($publicSchain['nodes'] !== []) {
            $this->supplyChainValidator->assertValid($publicSchain);
        }

        $pageTargeting = $this->targeting($site->targeting->whereNull('placement_id'), $environment);
        foreach ($config->page_targeting ?? [] as $key => $values) {
            $pageTargeting[$key] = array_values(array_map('strval', (array) $values));
        }
        if ($config->house_ad_testing) {
            $pageTargeting['hm_house_test'] = ['1'];
        }

        return [
            // v3 is additive. All schema-v2 fields below remain present so old
            // deployed Loaders and immutable rollback targets continue to work.
            'schemaVersion' => 3,
            'siteKey' => $site->public_key,
            'servingMode' => $site->serving_mode->value,
            'gamNetworkCode' => $networkCode,
            'engines' => $enginePayload,
            'configVersion' => $version,
            'environment' => $environment->value,
            'status' => $active ? 'active' : 'paused',
            'immediatePause' => (bool) $config->immediate_pause,
            'controls' => [
                'adServingDisabled' => $adServingDisabled,
                'gamDisabled' => $gamDisabled,
                'prebidDisabled' => $prebidDisabled,
                'directJsDisabled' => $directJsDisabled,
                'nativeDemandDisabled' => $legacyNativeDisabled,
            ],
            'prebidEnabled' => $legacyPrebidEnabled,
            // The legacy top-level prebidEnabled flag remains GAM-bridge only,
            // while schema-v3's prebid.enabled now reflects the resolved engine
            // so the permanent Loader can execute true standalone auctions.
            'prebid' => array_replace($prebid, ['enabled' => $effectivePrebidEnabled]),
            'nativeDemandEnabled' => $legacyNativeEnabled,
            'nativeDemand' => array_replace($publicNative, ['enabled' => $legacyNativeEnabled]),
            'debug' => (bool) $config->debug_enabled,
            'houseAdTesting' => (bool) $config->house_ad_testing,
            'clickGuard' => $this->clickGuard($config->click_guard_settings),
            'allowedHostnames' => $this->hostnames($site),
            'loader' => [
                'version' => $loader?->version ?? '2.0.0',
                'assetUrl' => $loader ? rtrim((string) config('horus.cdn_url'), '/').'/'.ltrim($loader->minified_path, '/') : null,
                'cacheBust' => $version,
            ],
            // GPT metadata remains for compatibility, but the Loader initializes
            // GPT only when a generated placement still has a GAM adUnitPath.
            'gpt' => [
                'url' => $tag?->gpt_url ?: config('horus.gpt_url'),
                'tagVersion' => $tag?->version ?? '1.0.0',
                'singleRequest' => (bool) $config->single_request_mode,
                'config' => array_replace_recursive([
                    'threadYield' => 'ENABLED_ALL_SLOTS',
                    'autoRefresh' => ['heavyAds' => true],
                ], $config->gpt_settings ?? []),
            ],
            'privacy' => array_replace_recursive([
                'mode' => 'AUTO',
                'cmp' => ['tcfVersion' => '2.3', 'gppVersion' => '1.1', 'timeoutMs' => 1200, 'actionOnTimeout' => 'LIMITED_ADS'],
                'signals' => ['gpc' => true, 'coppa' => false, 'underAgeOfConsent' => false],
                'requireConsentBeforeAds' => true,
            ], $config->privacy_settings ?? []),
            ...($publicSchain['nodes'] === [] ? [] : ['supplyChain' => ['schain' => $publicSchain]]),
            'observability' => array_replace_recursive([
                'runtimeTelemetry' => false,
                'localDiagnostics' => (bool) $config->debug_enabled,
                'syntheticProbeUrl' => rtrim((string) config('horus.cdn_url'), '/').'/health/delivery.json',
            ], $config->observability_settings ?? []),
            'pageTargeting' => $pageTargeting,
            'placements' => $site->placements
                ->sortBy('sort_order')
                ->map(fn (Placement $placement) => $this->placement(
                    $placement,
                    $effectiveGamEnabled ? $networkCode : null,
                    $environment,
                    $config->house_ad_testing,
                    (array) data_get($publicNative, 'placements.'.$placement->code, []),
                    in_array($placement->code, $standalonePrebidCodes, true),
                    $active,
                ))
                ->values()
                ->all(),
            'generatedAt' => now()->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function filterNativeDemand(array $native, bool $legacyNativeDisabled, bool $directJsOnlyDisabled): array
    {
        if ($legacyNativeDisabled) {
            return ['enabled' => false, 'fallbackOrder' => [], 'placements' => []];
        }
        if (! $directJsOnlyDisabled) {
            return $native;
        }

        $placements = [];
        foreach ((array) ($native['placements'] ?? []) as $code => $placement) {
            $candidates = collect((array) ($placement['candidates'] ?? []))
                ->filter(fn (array $candidate) => (bool) ($candidate['gamManaged'] ?? false))
                ->values()->all();
            $house = $placement['house'] ?? null;
            if ($candidates === [] && empty($house)) {
                continue;
            }
            $placements[$code] = [
                'enabled' => true,
                'candidates' => $candidates,
                'house' => $house,
            ];
        }

        return [
            'enabled' => $placements !== [],
            'fallbackOrder' => (array) ($native['fallbackOrder'] ?? []),
            'placements' => $placements,
        ];
    }

    private function hasDirectJsCandidate(array $native): bool
    {
        return collect((array) ($native['placements'] ?? []))
            ->contains(fn (array $placement) => collect((array) ($placement['candidates'] ?? []))
                ->contains(fn (array $candidate) => ! (bool) ($candidate['gamManaged'] ?? false)));
    }

    private function clickGuard(?array $settings): array
    {
        $settings ??= [];
        $enabled = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return [
            'enabled' => $enabled ?? false,
            'maxClicks' => $this->boundedInteger($settings['maxClicks'] ?? null, 3, 1, 50),
            'windowHours' => $this->boundedInteger($settings['windowHours'] ?? null, 6, 1, 168),
            'blockHours' => $this->boundedInteger($settings['blockHours'] ?? null, 12, 1, 720),
        ];
    }

    private function boundedInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false) {
            return $default;
        }

        return max($minimum, min($maximum, $validated));
    }

    private function placement(
        Placement $placement,
        ?string $networkCode,
        ConfigEnvironment $environment,
        bool $houseMode,
        array $native,
        bool $standalonePrebidEnabled,
        bool $siteServingActive,
    ): array {
        $sizes = $placement->sizes->where('is_active', true);
        $fixed = $sizes->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size) => [(int) $size->width, (int) $size->height])->unique()->values()->all();
        if ($sizes->contains(fn ($size) => $size->size_type === 'FLUID')) {
            $fixed[] = 'fluid';
        }

        $mappings = $sizes
            ->filter(fn ($size) => $size->min_viewport_width > 0 || $size->device->value !== 'ALL')
            ->groupBy(fn ($size) => $size->min_viewport_width.'x'.$size->min_viewport_height.'|'.$size->device->value)
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'viewport' => [(int) $first->min_viewport_width, (int) $first->min_viewport_height],
                    'maxViewport' => [$first->max_viewport_width ? (int) $first->max_viewport_width : null, $first->max_viewport_height ? (int) $first->max_viewport_height : null],
                    'device' => $first->device->value,
                    'sizes' => $group->map(fn ($size) => $size->size_type === 'FLUID' ? 'fluid' : [(int) $size->width, (int) $size->height])->values()->all(),
                ];
            })->sortByDesc(fn ($mapping) => $mapping['viewport'][0])->values()->all();

        $targeting = $this->targeting($placement->targeting, $environment);
        if ($houseMode) {
            $targeting['hm_house_test'] = ['1'];
        }

        $placementDisabled = $this->controls->placementDisabled($placement->id);
        $gamEnabled = $siteServingActive && ! $placementDisabled && (bool) $placement->adUnit?->is_enabled && (bool) $networkCode;
        $directJsPlacementDisabled = $this->controls->placementEngineDisabled($placement->id, 'DIRECT_JS');
        $prebidPlacementDisabled = $this->controls->placementEngineDisabled($placement->id, 'PREBID');
        $directCandidates = collect((array) ($native['candidates'] ?? []))
            ->filter(fn (array $candidate) => ! (bool) ($candidate['gamManaged'] ?? false))
            ->values()->all();
        $directJsEnabled = $siteServingActive
            && ! $directJsPlacementDisabled
            && (bool) ($native['enabled'] ?? false)
            && $directCandidates !== [];
        $houseEnabled = $siteServingActive && (bool) ($native['enabled'] ?? false) && ! empty($native['house']);
        $standalonePrebidEnabled = $siteServingActive && $standalonePrebidEnabled && ! $prebidPlacementDisabled;
        $rendererConflict = ! $gamEnabled && $standalonePrebidEnabled && $directJsEnabled;

        $renderer = match (true) {
            $rendererConflict => 'CONFLICT',
            $gamEnabled => 'GAM',
            $standalonePrebidEnabled => 'PREBID_STANDALONE',
            $directJsEnabled => 'DIRECT_JS',
            $houseEnabled => 'HOUSE',
            default => 'NONE',
        };
        $legacyNativeEnabled = $siteServingActive
            && (bool) ($native['enabled'] ?? false)
            && (((array) ($native['candidates'] ?? [])) !== [] || $houseEnabled);
        $eligible = ! $rendererConflict && in_array($renderer, ['GAM', 'PREBID_STANDALONE', 'DIRECT_JS', 'HOUSE'], true);

        return [
            'code' => $placement->code,
            'name' => $placement->name,
            'type' => $placement->type->value,
            'format' => $placement->adFormat ? [
                'code' => $placement->adFormat->code,
                'mediaType' => $placement->adFormat->media_type,
                'capabilities' => $placement->adFormat->capabilities ?? [],
                'settings' => array_replace_recursive($placement->adFormat->defaults ?? [], $placement->format_settings ?? []),
            ] : null,
            'status' => strtolower($placement->status->value),
            'enabled' => $siteServingActive && ! $placementDisabled && $placement->status === PlacementStatus::Active && $eligible,
            'renderer' => $renderer,
            'rendererConflict' => $rendererConflict,
            'gamEnabled' => $gamEnabled,
            'prebidStandaloneEnabled' => $standalonePrebidEnabled,
            'directJsEnabled' => $directJsEnabled,
            'nativeEnabled' => $legacyNativeEnabled,
            'adUnitPath' => $gamEnabled && $placement->adUnit ? '/'.$networkCode.'/'.ltrim($placement->adUnit->code, '/') : null,
            'sizes' => $fixed,
            'responsiveMappings' => $mappings,
            'targeting' => $targeting,
            'lazyLoad' => [
                'enabled' => (bool) $placement->lazy_load_enabled,
                'fetchMarginPercent' => (int) $placement->lazy_fetch_margin_percent,
                'renderMarginPercent' => (int) $placement->lazy_render_margin_percent,
                'mobileScaling' => (float) $placement->lazy_mobile_scaling,
            ],
            'refresh' => [
                'enabled' => (bool) $placement->refresh_enabled,
                'intervalSeconds' => $placement->refresh_interval_seconds ? (int) $placement->refresh_interval_seconds : null,
                'limit' => $placement->refresh_limit ? (int) $placement->refresh_limit : null,
            ],
            'collapseEmptyDiv' => (bool) $placement->collapse_empty_div,
            'safeFrame' => (bool) $placement->safeframe_enabled,
            'outOfPageFormat' => match ($placement->type) {
                PlacementType::Interstitial => 'INTERSTITIAL',
                PlacementType::Rewarded => 'REWARDED',
                default => null,
            },
        ];
    }

    private function targeting($records, ConfigEnvironment $environment): array
    {
        return $records
            ->filter(fn ($record) => $record->is_active && (! $record->environment || $record->environment === $environment->value))
            ->sortBy('targeting_key')
            ->mapWithKeys(fn ($record) => [$record->targeting_key => array_values(array_map('strval', $record->targeting_values ?? []))])
            ->all();
    }

    private function hostnames(Site $site): array
    {
        return collect([$site->primary_domain])
            ->merge($site->domains->pluck('domain'))
            ->map(fn ($domain) => strtolower(preg_replace('#^https?://#i', '', trim((string) $domain))))
            ->map(fn ($domain) => explode('/', $domain)[0])
            ->map(fn ($domain) => explode(':', $domain)[0])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
