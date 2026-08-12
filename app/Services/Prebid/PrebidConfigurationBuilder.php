<?php

namespace App\Services\Prebid;

use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Enums\PrebidDeliveryMode;
use App\Models\BidderSiteMapping;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PrebidBuild;
use App\Models\PrebidPriceBucket;
use App\Models\Site;

final class PrebidConfigurationBuilder
{
    public function __construct(private readonly PrebidManager $manager)
    {
    }

    public function build(
        Site $site,
        ?GamConnection $connection = null,
        PrebidDeliveryMode $deliveryMode = PrebidDeliveryMode::GamBridge,
    ): array {
        $context = $deliveryMode === PrebidDeliveryMode::GamBridge
            ? $this->gamBridgeContext($connection)
            : $this->standaloneContext();
        $build = $context['build'];
        $site->loadMissing(['placements.sizes']);

        $siteMappings = BidderSiteMapping::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('enabled', true)
            ->with([
                'account.bidder.adapter',
                'placementMappings' => fn ($query) => $query->where('enabled', true)->orderBy('sequence'),
            ])
            ->orderBy('sequence')
            ->get();

        $adUnits = $site->placements
            ->filter(fn (Placement $placement) => $placement->status === PlacementStatus::Active)
            ->map(function (Placement $placement) use ($siteMappings): ?array {
                $bids = $siteMappings->map(function ($siteMapping) use ($placement): ?array {
                    $placementMapping = $siteMapping->placementMappings->firstWhere('placement_id', $placement->id);
                    if (! $placementMapping || ! $siteMapping->account?->enabled || ! $siteMapping->account?->bidder?->enabled || ! $siteMapping->account?->bidder?->adapter?->enabled) {
                        return null;
                    }

                    $bidder = $siteMapping->account->bidder;
                    $adapter = $bidder->adapter;
                    $params = array_merge(
                        $bidder->default_public_parameters ?? [],
                        $siteMapping->account->public_parameters ?? [],
                        $siteMapping->public_parameters ?? [],
                        $placementMapping->public_parameters ?? [],
                    );
                    if ($adapter->publisher_parameter && filled($siteMapping->account->publisher_id)) {
                        $params[$adapter->publisher_parameter] = (string) $siteMapping->account->publisher_id;
                    }
                    if ($adapter->placement_parameter && filled($placementMapping->placement_id_value)) {
                        $params[$adapter->placement_parameter] = (string) $placementMapping->placement_id_value;
                    }
                    $missing = collect($adapter->required_public_parameters ?? [])
                        ->filter(fn ($key) => ! array_key_exists($key, $params) || $params[$key] === '')
                        ->values();
                    if ($missing->isNotEmpty()) {
                        return null;
                    }

                    return ['bidder' => $bidder->code, 'params' => $params];
                })->filter()->values()->all();

                if ($bids === []) {
                    return null;
                }

                return [
                    'code' => $placement->code,
                    'mediaTypes' => $this->mediaTypes($placement),
                    'ortb2Imp' => ['ext' => ['gpid' => $placement->code]],
                    'bids' => $bids,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $enabled = (bool) $site->prebid_enabled
            && (bool) $context['enabled']
            && $build !== null
            && $adUnits !== [];

        return [
            'enabled' => $enabled,
            'deliveryMode' => $deliveryMode->value,
            'build' => [
                'version' => $build?->version,
                'url' => $build ? config('prebid.cdn_url').'/'.ltrim($build->minified_path, '/') : null,
                'checksum' => $build?->checksum,
            ],
            'auction' => [
                'timeoutMs' => (int) $context['auction_timeout_ms'],
                'priceGranularity' => $context['price_granularity'],
                'currency' => strtoupper((string) $context['currency']),
                'bidderSequence' => $context['bidder_sequence'],
                'consent' => $context['consent_behavior'],
                'allowActivities' => [
                    'accessDevice' => ['default' => false],
                    'syncUser' => ['default' => false],
                    'transmitPreciseGeo' => ['default' => false],
                ],
                'ortb2' => ['site' => ['domain' => $site->primary_domain, 'publisher' => ['id' => $site->public_key]]],
            ],
            'delivery' => [
                'mode' => $deliveryMode->value,
                'lazyLoading' => $context['lazy_loading'],
                'refreshBehavior' => $context['refresh_behavior'],
                'bidderTimeoutReporting' => (bool) $context['bidder_timeout_reporting'],
                'gamFallback' => $deliveryMode === PrebidDeliveryMode::GamBridge && (bool) $context['gam_fallback'],
            ],
            'adUnits' => $adUnits,
        ];
    }

    /** @return array<string, mixed> */
    private function gamBridgeContext(?GamConnection $connection): array
    {
        if ($connection === null) {
            return $this->disabledContext();
        }

        // Existing GAM behavior remains authoritative, including managed defaults.
        $settings = $this->manager->settingsFor($connection);
        $settings->loadMissing('build');

        return [
            'enabled' => (bool) $settings->enabled,
            'build' => $settings->build,
            'auction_timeout_ms' => (int) $settings->auction_timeout_ms,
            'price_granularity' => $this->priceGranularity($connection, $settings->price_granularity),
            'currency' => (string) $settings->currency,
            'bidder_sequence' => $settings->bidder_sequence,
            'consent_behavior' => $settings->consent_behavior ?? [],
            'lazy_loading' => $settings->lazy_loading ?? ['enabled' => true],
            'refresh_behavior' => $settings->refresh_behavior ?? ['enabled' => true, 'minimumIntervalSeconds' => 30],
            'bidder_timeout_reporting' => (bool) $settings->bidder_timeout_reporting,
            'gam_fallback' => (bool) $settings->gam_fallback,
        ];
    }

    /** @return array<string, mixed> */
    private function standaloneContext(): array
    {
        $build = PrebidBuild::query()->where('is_active', true)->latest('built_at')->first();

        return [
            'enabled' => true,
            'build' => $build,
            'auction_timeout_ms' => (int) config('prebid.default_timeout_ms', 1200),
            'price_granularity' => 'medium',
            'currency' => (string) config('prebid.default_currency', 'USD'),
            'bidder_sequence' => 'fixed',
            'consent_behavior' => [
                'gdpr' => ['cmpApi' => 'iab', 'timeout' => 800, 'defaultGdprScope' => true],
                'gpp' => ['cmpApi' => 'iab', 'timeout' => 800],
            ],
            'lazy_loading' => ['enabled' => true],
            'refresh_behavior' => ['enabled' => true, 'minimumIntervalSeconds' => 30],
            'bidder_timeout_reporting' => true,
            'gam_fallback' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function disabledContext(): array
    {
        return [
            'enabled' => false,
            'build' => null,
            'auction_timeout_ms' => (int) config('prebid.default_timeout_ms', 1200),
            'price_granularity' => 'medium',
            'currency' => (string) config('prebid.default_currency', 'USD'),
            'bidder_sequence' => 'fixed',
            'consent_behavior' => [],
            'lazy_loading' => ['enabled' => true],
            'refresh_behavior' => ['enabled' => true, 'minimumIntervalSeconds' => 30],
            'bidder_timeout_reporting' => false,
            'gam_fallback' => true,
        ];
    }

    private function mediaTypes(Placement $placement): array
    {
        $sizes = $placement->sizes
            ->where('is_active', true)
            ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size) => [(int) $size->width, (int) $size->height])
            ->unique()
            ->values()
            ->all();

        return match ($placement->type) {
            PlacementType::Video, PlacementType::Rewarded => ['video' => [
                'playerSize' => $sizes, 'context' => 'outstream', 'plcmt' => 4,
                'mimes' => ['video/mp4', 'video/webm'], 'protocols' => [2, 3, 5, 6],
                'api' => [2, 7], 'linearity' => 1,
            ]],
            PlacementType::Native => ['native' => ['ortb' => [
                'assets' => [
                    ['id' => 1, 'required' => 1, 'title' => ['len' => 90]],
                    ['id' => 2, 'required' => 1, 'img' => ['type' => 3, 'wmin' => 300, 'hmin' => 200]],
                    ['id' => 3, 'required' => 1, 'data' => ['type' => 2, 'len' => 140]],
                ],
                'eventtrackers' => [
                    ['event' => 1, 'methods' => [1]],
                    ['event' => 2, 'methods' => [1, 2]],
                ],
            ]]],
            default => ['banner' => ['sizes' => $sizes]],
        };
    }

    private function priceGranularity(GamConnection $connection, string $name): string|array
    {
        if ($name !== 'custom') {
            return $name;
        }

        $buckets = PrebidPriceBucket::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($bucket) => [
                'min' => (float) $bucket->minimum,
                'max' => (float) $bucket->maximum,
                'increment' => (float) $bucket->increment,
                'precision' => (int) $bucket->precision,
            ])
            ->values()
            ->all();

        return ['buckets' => $buckets];
    }
}
