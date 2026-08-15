<?php

namespace App\Services\Prebid;

use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Enums\PrebidDeliveryMode;
use App\Models\BidderSiteMapping;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PrebidPriceBucket;
use App\Models\Site;
use App\Services\SupplyChain\DomainNormalizer;
use InvalidArgumentException;

final class PrebidConfigurationBuilder
{
    public function __construct(
        private readonly PrebidManager $manager,
        private readonly DomainNormalizer $domains,
    ) {}

    public function build(
        Site $site,
        ?GamConnection $connection = null,
        PrebidDeliveryMode $deliveryMode = PrebidDeliveryMode::GamBridge,
    ): array {
        $context = $deliveryMode === PrebidDeliveryMode::GamBridge
            ? $this->gamBridgeContext($connection)
            : $this->standaloneContext($site);
        $build = $context['build'];
        $site->loadMissing(['publisher', 'placements.sizes']);

        $siteMappings = BidderSiteMapping::withoutGlobalScopes()
            ->where('organization_id', $site->organization_id)
            ->where('site_id', $site->id)
            ->where('enabled', true)
            ->with([
                'account.bidder.adapter',
                'placementMappings' => fn ($query) => $query
                    ->where('organization_id', $site->organization_id)
                    ->where('enabled', true)
                    ->orderBy('sequence'),
            ])
            ->orderBy('sequence')
            ->get();

        $adUnits = $site->placements
            ->filter(fn (Placement $placement) => $placement->status === PlacementStatus::Active)
            ->map(function (Placement $placement) use ($siteMappings, $deliveryMode, $site): ?array {
                $mediaTypes = $this->mediaTypes($placement);
                if ($deliveryMode === PrebidDeliveryMode::Standalone && ! isset($mediaTypes['banner'])) {
                    return null;
                }
                if (isset($mediaTypes['banner']) && ($mediaTypes['banner']['sizes'] ?? []) === []) {
                    return null;
                }

                $bids = $siteMappings->map(function ($siteMapping) use ($placement, $site): ?array {
                    $placementMapping = $siteMapping->placementMappings->firstWhere('placement_id', $placement->id);
                    $account = $siteMapping->account;
                    $bidder = $account?->bidder;
                    $adapter = $bidder?->adapter;
                    if (! $placementMapping
                        || (string) $siteMapping->organization_id !== (string) $site->organization_id
                        || (string) $placementMapping->organization_id !== (string) $site->organization_id
                        || ! $account?->enabled || ! $bidder?->enabled || ! $adapter?->enabled) {
                        return null;
                    }

                    $params = array_merge(
                        $bidder->default_public_parameters ?? [],
                        $account->public_parameters ?? [],
                        $siteMapping->public_parameters ?? [],
                        $placementMapping->public_parameters ?? [],
                    );
                    if ($adapter->publisher_parameter && filled($account->publisher_id)) {
                        $params[$adapter->publisher_parameter] = (string) $account->publisher_id;
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
                    'mediaTypes' => $mediaTypes,
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
            && filled($build->minified_path)
            && $adUnits !== [];

        $publisher = array_filter([
            'name' => $site->publisher?->display_name ?: $site->publisher?->legal_name,
            'domain' => $this->publisherDomain($site),
        ], fn ($value): bool => filled($value));
        $ortbSite = ['domain' => strtolower(rtrim((string) $site->primary_domain, '.'))];
        if ($publisher !== []) {
            $ortbSite['publisher'] = $publisher;
        }

        return [
            'enabled' => $enabled,
            'hasProfile' => (bool) $context['has_profile'],
            'deliveryMode' => $deliveryMode->value,
            'build' => [
                'version' => $build?->version,
                'url' => $build ? rtrim((string) config('prebid.cdn_url'), '/').'/'.ltrim($build->minified_path, '/') : null,
                'checksum' => $build?->checksum,
                'modules' => array_values((array) $build?->modules),
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
                'storageControl' => ['enforcement' => 'strict'],
                'ortb2' => ['site' => $ortbSite],
            ],
            'delivery' => [
                'mode' => $deliveryMode->value,
                'lazyLoading' => $context['lazy_loading'],
                'refreshBehavior' => $context['refresh_behavior'],
                'bidderTimeoutReporting' => (bool) $context['bidder_timeout_reporting'],
                'gamFallback' => $deliveryMode === PrebidDeliveryMode::GamBridge && (bool) $context['gam_fallback'],
            ],
            'directRender' => $deliveryMode === PrebidDeliveryMode::Standalone ? [
                'implemented' => true,
                'supportedMediaTypes' => ['banner'],
                'suppressExpiredRender' => true,
                'allowTopWindowRenderers' => false,
                'sandbox' => [
                    'allow-forms',
                    'allow-popups',
                    'allow-popups-to-escape-sandbox',
                    'allow-same-origin',
                    'allow-scripts',
                    'allow-top-navigation-by-user-activation',
                ],
            ] : [
                'implemented' => false,
                'supportedMediaTypes' => [],
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
        $settings = $this->manager->settingsFor($connection);
        $settings->loadMissing('build');

        return [
            'has_profile' => true,
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
    private function standaloneContext(Site $site): array
    {
        $settings = $this->manager->settingsForSite($site);
        $settings->loadMissing('build');

        return [
            'has_profile' => true,
            'enabled' => (bool) $settings->enabled,
            'build' => $settings->build,
            'auction_timeout_ms' => (int) $settings->auction_timeout_ms,
            'price_granularity' => $settings->price_granularity ?: 'medium',
            'currency' => (string) $settings->currency,
            'bidder_sequence' => $settings->bidder_sequence ?: 'fixed',
            'consent_behavior' => $settings->consent_behavior ?? [],
            'lazy_loading' => $settings->lazy_loading ?? ['enabled' => true],
            'refresh_behavior' => $settings->refresh_behavior ?? ['enabled' => true, 'minimumIntervalSeconds' => 30],
            'bidder_timeout_reporting' => (bool) $settings->bidder_timeout_reporting,
            'gam_fallback' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function disabledContext(): array
    {
        return [
            'has_profile' => false,
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

    private function publisherDomain(Site $site): ?string
    {
        $domain = trim((string) $site->publisher?->business_domain);
        if ($domain === '') {
            return null;
        }
        try {
            return $this->domains->normalize($domain);
        } catch (InvalidArgumentException) {
            return null;
        }
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
