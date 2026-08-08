<?php

namespace App\Services\Inventory;

use App\Enums\ConfigEnvironment;
use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\LoaderRelease;
use App\Models\Placement;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Models\TagVersion;
use App\Models\SellerDeclaration;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Prebid\PrebidConfigurationBuilder;
use App\Services\Operations\PlatformControlService;

final class SiteConfigurationBuilder
{
    public function __construct(
        private readonly GamConnectionResolver $connections,
        private readonly PrebidConfigurationBuilder $prebid,
        private readonly DemandConfigurationBuilder $demand,
        private readonly PlatformControlService $controls,
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
        $connection = $this->connections->resolve($site);
        $networkCode = $connection?->network_code ?: $site->current_gam_network_code;
        $loader = $config->loaderRelease ?: LoaderRelease::query()->where('is_active', true)->latest('published_at')->first();
        $tag = $config->tagVersion ?: TagVersion::query()->where('is_active', true)->latest('published_at')->first();
        $adServingDisabled = $this->controls->disabledForSite('AD_SERVING', $site->id, $connection?->id);
        $prebidDisabled = $this->controls->disabledForSite('PREBID', $site->id);
        $nativeDisabled = $this->controls->disabledForSite('NATIVE_DEMAND', $site->id);
        $active = $site->status === SiteStatus::Active
            && ! $adServingDisabled && $config->status === 'ACTIVE'
            && ! $config->immediate_pause
            && $site->serving_mode !== ServingMode::Paused;
        $prebid = $connection
            ? $this->prebid->build($site, $connection)
            : ['enabled' => false, 'build' => null, 'auction' => [], 'delivery' => ['gamFallback' => true], 'adUnits' => []];
        $native = $this->demand->build($site);

        $pageTargeting = $this->targeting($site->targeting->whereNull('placement_id'), $environment);
        foreach ($config->page_targeting ?? [] as $key => $values) {
            $pageTargeting[$key] = array_values(array_map('strval', (array) $values));
        }
        if ($config->house_ad_testing) {
            $pageTargeting['hm_house_test'] = ['1'];
        }

        return [
            'schemaVersion' => 2,
            'siteKey' => $site->public_key,
            'servingMode' => $site->serving_mode->value,
            'gamNetworkCode' => $networkCode,
            'configVersion' => $version,
            'environment' => $environment->value,
            'status' => $active ? 'active' : 'paused',
            'immediatePause' => (bool) $config->immediate_pause,
            'controls' => ['adServingDisabled' => $adServingDisabled, 'prebidDisabled' => $prebidDisabled, 'nativeDemandDisabled' => $nativeDisabled],
            'prebidEnabled' => ! $prebidDisabled && (bool) $prebid['enabled'],
            'prebid' => array_replace($prebid, ['enabled' => ! $prebidDisabled && (bool) $prebid['enabled']]),
            'nativeDemandEnabled' => ! $nativeDisabled && (bool) $native['enabled'],
            'nativeDemand' => array_replace($native, ['enabled' => ! $nativeDisabled && (bool) $native['enabled']]),
            'debug' => (bool) $config->debug_enabled,
            'houseAdTesting' => (bool) $config->house_ad_testing,
            'clickGuard' => $this->clickGuard($config->click_guard_settings),
            'allowedHostnames' => $this->hostnames($site),
            'loader' => [
                'version' => $loader?->version ?? '2.0.0',
                'assetUrl' => $loader ? rtrim((string) config('horus.cdn_url'), '/').'/'.ltrim($loader->minified_path, '/') : null,
                'cacheBust' => $version,
            ],
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
            'supplyChain' => array_replace_recursive([
                'adsTxtUrl' => 'https://'.$site->primary_domain.'/ads.txt',
                'sellersJsonUrl' => rtrim((string) config('horus.cdn_url'), '/').'/supply/sellers.json',
                'cdnAdsTxtUrl' => rtrim((string) config('horus.cdn_url'), '/').'/supply/sites/'.$site->public_key.'/ads.txt',
                'schain' => ['complete' => 1, 'ver' => '1.0', 'nodes' => $this->schainNodes($site)],
            ], $config->supply_chain_settings ?? []),
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
                    $networkCode,
                    $environment,
                    $config->house_ad_testing,
                    $nativeDisabled ? [] : (array) data_get($native, 'placements.'.$placement->code, []),
                ))
                ->values()
                ->all(),
            'generatedAt' => now()->utc()->toIso8601String(),
        ];
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
        $gamEnabled = ! $placementDisabled && (bool) $placement->adUnit?->is_enabled && (bool) $networkCode;
        $nativeEnabled = (bool) ($native['enabled'] ?? false)
            && (((array) ($native['candidates'] ?? [])) !== [] || ! empty($native['house']));

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
            'enabled' => ! $placementDisabled && $placement->status === PlacementStatus::Active && ($gamEnabled || $nativeEnabled),
            'gamEnabled' => $gamEnabled,
            'nativeEnabled' => $nativeEnabled,
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

    private function schainNodes(Site $site): array
    {
        return SellerDeclaration::withoutGlobalScopes()
            ->where('organization_id', $site->organization_id)
            ->where(fn ($query) => $query->whereNull('site_id')->orWhere('site_id', $site->id))
            ->where('status', 'ACTIVE')
            ->orderBy('seller_id')
            ->get()
            ->map(fn ($seller) => [
                'asi' => config('supply-chain.manager_domain', 'horusmedia.net'),
                'sid' => $seller->seller_id,
                'hp' => 1,
            ])->values()->all();
    }
}
