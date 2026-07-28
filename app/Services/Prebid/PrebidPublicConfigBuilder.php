<?php

namespace App\Services\Prebid;

use App\Enums\GamConnectionType;
use App\Models\BidderAccount;
use App\Models\BidderPlacementMapping;
use App\Models\BidderSiteMapping;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PrebidSetting;
use App\Models\Site;
use Illuminate\Support\Collection;
use Throwable;

final class PrebidPublicConfigBuilder
{
    public function __construct(
        private readonly PrebidRegistry $registry,
        private readonly PrebidPriceBucketService $priceBuckets,
    ) {
    }

    public function build(Site $site, ?GamConnection $connection): array
    {
        if (! $connection) {
            return $this->disabled('NO_GAM_CONNECTION');
        }

        $settings = PrebidSetting::withoutGlobalScopes()
            ->with(['build', 'priceBucket'])
            ->where('site_id', $site->id)
            ->where('gam_connection_id', $connection->id)
            ->first();

        if (! $settings || ! $settings->enabled || ! $site->prebid_enabled) {
            return $this->disabled('DISABLED_FOR_CONNECTION', $connection);
        }

        $build = $settings->build;
        if (! $build || ! $build->is_active || $build->status !== 'READY') {
            return $this->disabled('BUILD_UNAVAILABLE', $connection);
        }

        $scopeKeys = [$connection->id];
        if ($connection->type === GamConnectionType::HorusGam) {
            $scopeKeys[] = 'DEFAULT';
        }

        $siteMappings = BidderSiteMapping::withoutGlobalScopes()
            ->with('account.bidder.adapter')
            ->where('site_id', $site->id)
            ->whereIn('gam_connection_key', $scopeKeys)
            ->get()
            ->sortBy(fn (BidderSiteMapping $mapping): int => $mapping->gam_connection_key === $connection->id ? 1 : 0)
            ->values();
        $placementIds = $site->placements->pluck('id');
        $placementMappings = BidderPlacementMapping::withoutGlobalScopes()
            ->with('account.bidder.adapter')
            ->whereIn('placement_id', $placementIds)
            ->whereIn('gam_connection_key', $scopeKeys)
            ->get()
            ->sortBy(fn (BidderPlacementMapping $mapping): int => $mapping->gam_connection_key === $connection->id ? 1 : 0)
            ->groupBy('placement_id');

        $placements = [];
        $activeBidderCodes = [];
        foreach ($site->placements as $placement) {
            $placementConfig = $this->placement($placement, $siteMappings, $placementMappings->get($placement->id, collect()));
            $placements[$placement->code] = $placementConfig;
            foreach ($placementConfig['bids'] as $bid) {
                $activeBidderCodes[$bid['bidder']] = true;
            }
        }

        $enabled = collect($placements)->contains(fn (array $placement): bool => $placement['enabled'] && $placement['bids'] !== []);
        $priceGranularity = $settings->price_granularity;
        if (strtolower($priceGranularity) === 'custom' && $settings->priceBucket) {
            $priceGranularity = $this->priceBuckets->clientConfig($settings->priceBucket);
        }

        return [
            'enabled' => $enabled,
            'reason' => $enabled ? null : 'NO_ELIGIBLE_BIDDERS',
            'gamConnectionId' => $connection->id,
            'gamConnectionType' => $connection->type->value,
            'build' => [
                'version' => $build->version,
                'assetUrl' => rtrim((string) config('prebid.cdn_url'), '/').'/'.ltrim($build->minified_path, '/'),
                'checksum' => $build->checksum,
            ],
            'auctionTimeoutMs' => (int) $settings->auction_timeout_ms,
            'priceGranularity' => $priceGranularity,
            'currency' => ['adServerCurrency' => strtoupper($settings->currency_code)],
            'bidderSequence' => $settings->bidder_sequence,
            'consentManagement' => $settings->consent_behavior ?? [],
            'lazyLoading' => $settings->lazy_loading ?? ['enabled' => true],
            'refresh' => $settings->refresh_behavior ?? ['enabled' => true, 'auctionBeforeRefresh' => true],
            'timeoutReporting' => (bool) $settings->timeout_reporting,
            'gamFallback' => (bool) $settings->gam_fallback,
            'activeBidders' => array_keys($activeBidderCodes),
            'placements' => $placements,
        ];
    }

    private function placement(Placement $placement, Collection $siteMappings, Collection $placementMappings): array
    {
        $candidates = [];
        foreach ($siteMappings as $mapping) {
            $candidates[$mapping->bidder_account_id] = [
                'account' => $mapping->account,
                'enabled' => $mapping->enabled,
                'sequence' => $mapping->sequence,
                'siteParameters' => $mapping->public_parameters ?? [],
                'placementParameters' => [],
            ];
        }
        foreach ($placementMappings as $mapping) {
            $candidate = $candidates[$mapping->bidder_account_id] ?? [
                'account' => $mapping->account,
                'enabled' => true,
                'sequence' => 100,
                'siteParameters' => [],
                'placementParameters' => [],
            ];
            $candidate['enabled'] = $candidate['enabled'] && $mapping->enabled;
            $candidate['placementParameters'] = $mapping->public_parameters ?? [];
            $candidates[$mapping->bidder_account_id] = $candidate;
        }

        uasort($candidates, fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);
        $bids = [];
        foreach ($candidates as $candidate) {
            /** @var BidderAccount|null $account */
            $account = $candidate['account'];
            $bidder = $account?->bidder;
            $adapter = $bidder?->adapter;
            if (! $candidate['enabled'] || ! $account?->enabled || ! $bidder?->enabled || ! $adapter?->enabled) {
                continue;
            }
            if (! in_array($this->mediaTypeFor($placement), $adapter->supported_media_types ?? [], true)) {
                continue;
            }

            try {
                $parameters = array_replace_recursive(
                    $bidder->default_public_parameters ?? [],
                    $account->public_parameters ?? [],
                    $candidate['siteParameters'],
                    $candidate['placementParameters'],
                );
                $parameters = $this->registry->injectPublisherId($adapter, $account->publisher_id, $parameters);
                $parameters = $this->registry->normalizeAndValidate($adapter, $parameters, true);
            } catch (Throwable) {
                continue;
            }

            $bids[] = ['bidder' => $adapter->bidder_code, 'params' => $parameters];
        }

        return [
            'enabled' => $bids !== [],
            'adUnitCode' => $placement->code,
            'mediaTypes' => $this->mediaTypes($placement),
            'bids' => $bids,
        ];
    }

    private function mediaTypeFor(Placement $placement): string
    {
        return match ($placement->type->value) {
            'VIDEO' => 'video',
            'NATIVE' => 'native',
            default => 'banner',
        };
    }

    private function mediaTypes(Placement $placement): array
    {
        $custom = data_get($placement->metadata, 'prebid_media_types');
        if (is_array($custom) && $custom !== []) {
            return $custom;
        }

        $sizes = $placement->sizes
            ->where('is_active', true)
            ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size) => [(int) $size->width, (int) $size->height])
            ->unique()
            ->values()
            ->all();

        return match ($this->mediaTypeFor($placement)) {
            'video' => ['video' => ['context' => 'instream', 'playerSize' => $sizes]],
            'native' => ['native' => [
                'title' => ['required' => true, 'len' => 120],
                'body' => ['required' => false, 'len' => 200],
                'image' => ['required' => true, 'sizes' => $sizes[0] ?? [1200, 627]],
                'icon' => ['required' => false, 'sizes' => [50, 50]],
                'sponsoredBy' => ['required' => true],
                'cta' => ['required' => false],
            ]],
            default => ['banner' => ['sizes' => $sizes]],
        };
    }

    private function disabled(string $reason, ?GamConnection $connection = null): array
    {
        return [
            'enabled' => false,
            'reason' => $reason,
            'gamConnectionId' => $connection?->id,
            'gamConnectionType' => $connection?->type->value,
            'build' => null,
            'auctionTimeoutMs' => (int) config('prebid.default_timeout_ms', 1200),
            'priceGranularity' => 'medium',
            'currency' => ['adServerCurrency' => config('prebid.default_currency', 'USD')],
            'bidderSequence' => 'random',
            'consentManagement' => [],
            'lazyLoading' => ['enabled' => true],
            'refresh' => ['enabled' => true, 'auctionBeforeRefresh' => true],
            'timeoutReporting' => false,
            'gamFallback' => true,
            'activeBidders' => [],
            'placements' => [],
        ];
    }
}
