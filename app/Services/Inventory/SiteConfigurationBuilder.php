<?php

namespace App\Services\Inventory;

use App\Enums\ConfigEnvironment;
use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Enums\ServingMode;
use App\Models\LoaderRelease;
use App\Models\Placement;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Models\TagVersion;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Prebid\PrebidPublicConfigBuilder;
use Illuminate\Support\Arr;

final class SiteConfigurationBuilder
{
    public function __construct(
        private readonly GamConnectionResolver $connections,
        private readonly PrebidPublicConfigBuilder $prebid,
    ) {
    }

    public function build(Site $site, ConfigEnvironment $environment, int $version): array
    {
        $site->loadMissing([
            'domains', 'placements.adUnit', 'placements.sizes', 'placements.targeting',
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
        $active = $config->status === 'ACTIVE'
            && ! $config->immediate_pause
            && $site->serving_mode !== ServingMode::Paused;
        $prebid = $this->prebid->build($site, $connection);

        $pageTargeting = $this->targeting($site->targeting->whereNull('placement_id'), $environment);
        foreach ($config->page_targeting ?? [] as $key => $values) {
            $pageTargeting[$key] = array_values(array_map('strval', (array) $values));
        }
        if ($config->house_ad_testing) {
            $pageTargeting['hm_house_test'] = ['1'];
        }

        return [
            'siteKey' => $site->public_key,
            'servingMode' => $site->serving_mode->value,
            'gamNetworkCode' => $networkCode,
            'configVersion' => $version,
            'environment' => $environment->value,
            'status' => $active ? 'active' : 'paused',
            'immediatePause' => (bool) $config->immediate_pause,
            'prebidEnabled' => (bool) $prebid['enabled'],
            'prebid' => Arr::except($prebid, ['placements']),
            'debug' => (bool) $config->debug_enabled,
            'houseAdTesting' => (bool) $config->house_ad_testing,
            'allowedHostnames' => $this->hostnames($site),
            'loader' => [
                'version' => $loader?->version ?? '1.1.0',
                'assetUrl' => $loader ? rtrim((string) config('horus.cdn_url'), '/').'/'.ltrim($loader->minified_path, '/') : null,
                'cacheBust' => $version,
            ],
            'gpt' => [
                'url' => $tag?->gpt_url ?: config('horus.gpt_url'),
                'tagVersion' => $tag?->version ?? '1.0.0',
                'singleRequest' => (bool) $config->single_request_mode,
            ],
            'pageTargeting' => $pageTargeting,
            'placements' => $site->placements
                ->sortBy('sort_order')
                ->map(function (Placement $placement) use ($networkCode, $environment, $config, $prebid): array {
                    $payload = $this->placement($placement, $networkCode, $environment, $config->house_ad_testing);
                    $payload['prebid'] = $prebid['placements'][$placement->code] ?? [
                        'enabled' => false,
                        'adUnitCode' => $placement->code,
                        'mediaTypes' => [],
                        'bids' => [],
                    ];

                    return $payload;
                })
                ->values()
                ->all(),
            'generatedAt' => now()->utc()->toIso8601String(),
        ];
    }

    private function placement(Placement $placement, ?string $networkCode, ConfigEnvironment $environment, bool $houseMode): array
    {
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
                    'device' => $first->device->value,
                    'sizes' => $group->map(fn ($size) => $size->size_type === 'FLUID' ? 'fluid' : [(int) $size->width, (int) $size->height])->values()->all(),
                ];
            })->sortByDesc(fn ($mapping) => $mapping['viewport'][0])->values()->all();

        $targeting = $this->targeting($placement->targeting, $environment);
        if ($houseMode) {
            $targeting['hm_house_test'] = ['1'];
        }

        return [
            'code' => $placement->code,
            'name' => $placement->name,
            'type' => $placement->type->value,
            'status' => strtolower($placement->status->value),
            'enabled' => $placement->status === PlacementStatus::Active && (bool) $placement->adUnit?->is_enabled,
            'adUnitPath' => $networkCode && $placement->adUnit ? '/'.$networkCode.'/'.ltrim($placement->adUnit->code, '/') : null,
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
