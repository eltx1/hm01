<?php

namespace App\Services\Prebid;

use App\Enums\PlacementType;
use App\Enums\PrebidPriceGranularity;
use App\Models\BidderSiteMapping;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PrebidGamRemoteObject;
use App\Models\PrebidGamTemplate;
use App\Models\PrebidSetting;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class PrebidConfigurationBuilder
{
    public function __construct(
        private readonly PrebidSettingsManager $settings,
        private readonly PrebidParameterValidator $parameters,
    ) {
    }

    public function build(Site $site, ?GamConnection $connection): array
    {
        $site->loadMissing(['placements.sizes']);
        $setting = $this->settings->ensureForSite($site)->loadMissing(['build', 'priceBuckets']);
        $mappings = BidderSiteMapping::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('is_enabled', true)
            ->with([
                'account.bidder.adapter',
                'placementMappings' => fn ($query) => $query->where('is_enabled', true)->orderBy('sequence'),
            ])
            ->orderBy('sequence')
            ->get();

        $enabled = (bool) $site->prebid_enabled
            && (bool) $setting->is_enabled
            && (bool) $setting->build?->is_active;

        $template = $connection
            ? PrebidGamTemplate::withoutGlobalScopes()
                ->where('gam_connection_id', $connection->id)
                ->where('status', 'ACTIVE')
                ->latest('version')
                ->first()
            : null;

        $adUnits = [];
        $errors = [];
        foreach ($site->placements as $placement) {
            $entry = $this->placement($placement, $mappings, $setting, $errors);
            if ($entry !== null) {
                $adUnits[$placement->code] = $entry;
            }
        }

        return [
            'enabled' => $enabled && $adUnits !== [],
            'build' => [
                'version' => $setting->build?->version,
                'prebidVersion' => $setting->build?->prebid_version,
                'assetUrl' => $setting->build
                    ? rtrim((string) config('horus.cdn_url'), '/').'/'.ltrim($setting->build->minified_path, '/')
                    : null,
                'checksum' => $setting->build?->checksum,
            ],
            'auctionTimeoutMs' => (int) $setting->auction_timeout_ms,
            'priceGranularity' => $this->priceGranularity($setting),
            'currency' => strtoupper($setting->currency),
            'bidderSequence' => strtolower($setting->bidder_sequence->value),
            'consentManagement' => $setting->consent_config ?? [],
            'userSync' => $setting->user_sync_config ?? [],
            'lazyLoadingEnabled' => (bool) $setting->lazy_loading_enabled,
            'refresh' => [
                'enabled' => (bool) $setting->refresh_enabled,
                'intervalSeconds' => $setting->refresh_interval_seconds ? (int) $setting->refresh_interval_seconds : null,
            ],
            'timeoutReportingEnabled' => (bool) $setting->timeout_reporting_enabled,
            'gamFallbackEnabled' => (bool) $setting->gam_fallback_enabled,
            'sendAllBids' => (bool) $setting->send_all_bids,
            'debug' => (bool) $setting->debug_enabled,
            'configurationVersion' => (int) $setting->configuration_version,
            'advancedConfig' => $setting->advanced_config ?? [],
            'gamSetup' => $this->gamSetup($connection, $template),
            'adUnits' => $adUnits,
            'configurationErrors' => $setting->debug_enabled ? $errors : [],
        ];
    }

    private function placement(Placement $placement, Collection $siteMappings, PrebidSetting $setting, array &$errors): ?array
    {
        if (! in_array($placement->type, [PlacementType::Display, PlacementType::Sticky, PlacementType::Custom], true)) {
            return null;
        }

        $sizes = $placement->sizes
            ->where('is_active', true)
            ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size) => [(int) $size->width, (int) $size->height])
            ->unique()
            ->values()
            ->all();

        if ($sizes === []) {
            return null;
        }

        $bids = [];
        foreach ($siteMappings as $siteMapping) {
            $account = $siteMapping->account;
            $bidder = $account?->bidder;
            $adapter = $bidder?->adapter;
            if (! $account?->is_enabled || ! $bidder?->is_enabled || ! $adapter?->is_enabled) {
                continue;
            }
            if (! in_array($adapter->module_name, $setting->build?->modules ?? [], true)) {
                $errors[] = "Bidder {$bidder->code} is enabled but its adapter is not present in build {$setting->build?->version}.";
                continue;
            }

            $placementMappings = $siteMapping->placementMappings;
            $placementMapping = $placementMappings->firstWhere('placement_id', $placement->id);
            if ($placementMappings->isNotEmpty() && ! $placementMapping) {
                continue;
            }

            $layers = [
                $bidder->defaults ?? [],
                $account->public_parameters ?? [],
                $siteMapping->public_parameters ?? [],
                $placementMapping?->public_parameters ?? [],
            ];
            $layers = $this->injectIdentifiers(
                $adapter->bidder_code,
                $adapter->required_public_parameters ?? [],
                $layers,
                $account->publisher_id,
                $siteMapping->publisher_id,
                $placementMapping?->placement_id_value,
            );

            try {
                $params = $this->parameters->mergeAndValidate($adapter, ...$layers);
            } catch (ValidationException $exception) {
                $errors[] = "Bidder {$bidder->code} skipped for {$placement->code}: ".collect($exception->errors())->flatten()->implode(' ');
                continue;
            }

            $bids[] = ['bidder' => $bidder->code, 'params' => $params];
        }

        if ($bids === []) {
            return null;
        }

        return [
            'mediaTypes' => ['banner' => ['sizes' => $sizes]],
            'bids' => array_values($bids),
        ];
    }

    private function injectIdentifiers(string $bidderCode, array $required, array $layers, ?string $accountPublisherId, ?string $sitePublisherId, ?string $placementId): array
    {
        $injected = [];
        $put = function (array $candidates, ?string $value) use (&$injected, $required): void {
            if (! filled($value)) {
                return;
            }
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $required, true)) {
                    $injected[$candidate] ??= $value;
                    return;
                }
            }
        };

        switch ($bidderCode) {
            case 'rubicon':
                $put(['accountId'], $accountPublisherId);
                $put(['siteId'], $sitePublisherId);
                $put(['zoneId'], $placementId);
                break;
            case 'pubmatic':
                $put(['publisherId'], $accountPublisherId ?: $sitePublisherId);
                $put(['adSlot'], $placementId);
                break;
            case 'appnexus':
                $put(['member'], $accountPublisherId);
                $put(['placementId'], $placementId ?: $sitePublisherId);
                break;
            case 'openx':
                $put(['unit'], $placementId ?: $sitePublisherId);
                break;
            case 'ix':
                $put(['siteId'], $sitePublisherId ?: $placementId ?: $accountPublisherId);
                break;
            default:
                $put(['publisherId', 'accountId', 'member'], $accountPublisherId ?: $sitePublisherId);
                $put(['placementId', 'adSlot', 'unit', 'zoneId', 'siteId'], $placementId);
        }

        $layers[] = $injected;

        return $layers;
    }

    private function priceGranularity(PrebidSetting $setting): string|array
    {
        if ($setting->price_granularity !== PrebidPriceGranularity::Custom) {
            return strtolower($setting->price_granularity->value);
        }

        return [
            'buckets' => $setting->priceBuckets
                ->where('is_enabled', true)
                ->sortBy('priority')
                ->map(fn ($bucket) => [
                    'min' => (float) $bucket->minimum,
                    'max' => (float) $bucket->maximum,
                    'increment' => (float) $bucket->increment,
                    'precision' => (int) $bucket->precision,
                ])->values()->all(),
        ];
    }

    private function gamSetup(?GamConnection $connection, ?PrebidGamTemplate $template): array
    {
        if (! $connection || ! $template) {
            return ['key' => null, 'version' => null, 'mode' => null, 'complete' => false];
        }

        $required = ['company', 'order', 'targeting-key:hb_pb'];
        $existing = PrebidGamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->where('prebid_gam_template_id', $template->id)
            ->pluck('object_key')
            ->all();

        return [
            'key' => hash('sha256', $connection->network_code.'|'.$template->id.'|'.$template->version),
            'version' => (int) $template->version,
            'mode' => $template->mode,
            'complete' => collect($required)->every(fn (string $key) => in_array($key, $existing, true)),
        ];
    }
}
