<?php

namespace App\Services\Prebid;

use App\Models\BidderAccount;
use App\Models\BidderPlacementMapping;
use App\Models\BidderSiteMapping;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PrebidAdapter;
use App\Models\PrebidBidder;
use App\Models\PrebidBuild;
use App\Models\PrebidGamTemplate;
use App\Models\PrebidPriceBucket;
use App\Models\PrebidSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrebidConfigurationService
{
    public function __construct(
        private readonly PrebidRegistry $registry,
        private readonly PrebidPriceBucketService $buckets,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function createAccount(Site $site, array $data, User $actor): BidderAccount
    {
        $adapter = PrebidAdapter::query()->findOrFail($data['prebid_adapter_id']);
        if (! $adapter->enabled) {
            throw ValidationException::withMessages(['prebid_adapter_id' => 'This Prebid adapter is disabled.']);
        }

        return DB::transaction(function () use ($site, $data, $actor, $adapter): BidderAccount {
            $bidder = PrebidBidder::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $site->organization_id, 'code' => $adapter->bidder_code],
                [
                    'prebid_adapter_id' => $adapter->id,
                    'name' => $adapter->adapter_name,
                    'enabled' => true,
                    'default_public_parameters' => [],
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );

            $parameters = $this->registry->injectPublisherId($adapter, $data['publisher_id'] ?? null, $data['public_parameters'] ?? []);
            $parameters = $this->registry->normalizeAndValidate($adapter, $parameters, false);
            $account = BidderAccount::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id,
                'prebid_bidder_id' => $bidder->id,
                'name' => trim($data['name']),
                'publisher_id' => filled($data['publisher_id'] ?? null) ? trim((string) $data['publisher_id']) : null,
                'public_parameters' => $parameters,
                'enabled' => (bool) ($data['enabled'] ?? true),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record('prebid.bidder_account.created', $site->organization_id, $actor, $account, newValues: [
                'site_id' => $site->id,
                'bidder_code' => $adapter->bidder_code,
                'publisher_id_configured' => filled($account->publisher_id),
                'public_parameter_keys' => array_keys($parameters),
            ]);

            return $account->load('bidder.adapter');
        });
    }

    public function assignToSite(BidderAccount $account, Site $site, ?GamConnection $connection, array $data, User $actor): BidderSiteMapping
    {
        $this->assertSameOrganization($account->organization_id, $site->organization_id);
        $this->assertConnection($site, $connection);
        $account->loadMissing('bidder.adapter');
        $parameters = $this->registry->normalizeAndValidate($account->bidder->adapter, $data['public_parameters'] ?? [], false);
        $key = $connection?->id ?? 'DEFAULT';

        $mapping = BidderSiteMapping::withoutGlobalScopes()->updateOrCreate(
            ['bidder_account_id' => $account->id, 'site_id' => $site->id, 'gam_connection_key' => $key],
            [
                'organization_id' => $site->organization_id,
                'gam_connection_id' => $connection?->id,
                'enabled' => (bool) ($data['enabled'] ?? true),
                'sequence' => max(0, min(65535, (int) ($data['sequence'] ?? 100))),
                'public_parameters' => $parameters,
            ],
        );

        $this->audit->record('prebid.bidder.assigned_to_site', $site->organization_id, $actor, $mapping, newValues: [
            'site_id' => $site->id,
            'bidder_account_id' => $account->id,
            'gam_connection_id' => $connection?->id,
            'enabled' => $mapping->enabled,
        ]);

        return $mapping;
    }

    public function assignToPlacement(BidderAccount $account, Placement $placement, ?GamConnection $connection, array $data, User $actor): BidderPlacementMapping
    {
        $placement->loadMissing('site');
        $site = $placement->site;
        $this->assertSameOrganization($account->organization_id, $site->organization_id);
        $this->assertConnection($site, $connection);
        $account->loadMissing('bidder.adapter');
        $parameters = $this->registry->normalizeAndValidate($account->bidder->adapter, $data['public_parameters'] ?? [], false);
        $key = $connection?->id ?? 'DEFAULT';

        $mapping = BidderPlacementMapping::withoutGlobalScopes()->updateOrCreate(
            ['bidder_account_id' => $account->id, 'placement_id' => $placement->id, 'gam_connection_key' => $key],
            [
                'organization_id' => $site->organization_id,
                'gam_connection_id' => $connection?->id,
                'enabled' => (bool) ($data['enabled'] ?? true),
                'public_parameters' => $parameters,
            ],
        );

        $this->audit->record('prebid.bidder.assigned_to_placement', $site->organization_id, $actor, $mapping, newValues: [
            'site_id' => $site->id,
            'placement_id' => $placement->id,
            'bidder_account_id' => $account->id,
            'gam_connection_id' => $connection?->id,
            'enabled' => $mapping->enabled,
        ]);

        return $mapping;
    }

    public function setAccountEnabled(BidderAccount $account, bool $enabled, User $actor): BidderAccount
    {
        $before = $account->enabled;
        $account->update(['enabled' => $enabled, 'updated_by' => $actor->id]);
        $this->audit->record('prebid.bidder_account.toggled', $account->organization_id, $actor, $account, ['enabled' => $before], ['enabled' => $enabled]);

        return $account->refresh();
    }

    public function saveSettings(Site $site, GamConnection $connection, array $data, User $actor): PrebidSetting
    {
        $this->assertConnection($site, $connection);
        $build = filled($data['prebid_build_id'] ?? null)
            ? PrebidBuild::query()->findOrFail($data['prebid_build_id'])
            : PrebidBuild::query()->where('is_active', true)->latest('built_at')->first();
        $bucket = filled($data['prebid_price_bucket_id'] ?? null)
            ? PrebidPriceBucket::withoutGlobalScopes()->findOrFail($data['prebid_price_bucket_id'])
            : $this->defaultBucket($connection, $actor);

        if ($bucket->organization_id !== $connection->organization_id) {
            throw ValidationException::withMessages(['prebid_price_bucket_id' => 'The price bucket belongs to another GAM network scope.']);
        }

        $settings = PrebidSetting::withoutGlobalScopes()->updateOrCreate(
            ['site_id' => $site->id, 'gam_connection_id' => $connection->id],
            [
                'organization_id' => $site->organization_id,
                'prebid_build_id' => $build?->id,
                'prebid_price_bucket_id' => $bucket->id,
                'enabled' => (bool) ($data['enabled'] ?? false),
                'auction_timeout_ms' => max(300, min(5000, (int) ($data['auction_timeout_ms'] ?? config('prebid.default_timeout_ms', 1200)))),
                'price_granularity' => $data['price_granularity'] ?? 'custom',
                'currency_code' => strtoupper((string) ($data['currency_code'] ?? $bucket->currency_code)),
                'bidder_sequence' => $data['bidder_sequence'] ?? 'random',
                'consent_behavior' => $data['consent_behavior'] ?? ['gdpr' => ['enabled' => true, 'allowAuctionWithoutConsent' => false, 'timeout' => 800], 'gpp' => ['enabled' => true, 'timeout' => 800]],
                'lazy_loading' => $data['lazy_loading'] ?? ['enabled' => true],
                'refresh_behavior' => $data['refresh_behavior'] ?? ['enabled' => true, 'auctionBeforeRefresh' => true],
                'timeout_reporting' => (bool) ($data['timeout_reporting'] ?? false),
                'gam_fallback' => (bool) ($data['gam_fallback'] ?? true),
                'created_by' => PrebidSetting::withoutGlobalScopes()->where('site_id', $site->id)->where('gam_connection_id', $connection->id)->value('created_by') ?? $actor->id,
                'updated_by' => $actor->id,
            ],
        );

        $site->update(['prebid_enabled' => PrebidSetting::withoutGlobalScopes()->where('site_id', $site->id)->where('enabled', true)->exists()]);
        $this->audit->record('prebid.settings.updated', $site->organization_id, $actor, $settings, newValues: [
            'site_id' => $site->id,
            'gam_connection_id' => $connection->id,
            'enabled' => $settings->enabled,
            'timeout_ms' => $settings->auction_timeout_ms,
            'currency' => $settings->currency_code,
            'gam_fallback' => $settings->gam_fallback,
        ]);

        return $settings->refresh()->load(['build', 'priceBucket', 'connection']);
    }

    public function savePriceBucket(GamConnection $connection, array $data, User $actor): PrebidPriceBucket
    {
        $ranges = $data['ranges'] ?? [];
        $this->buckets->values($ranges);
        $isDefault = (bool) ($data['is_default'] ?? false);

        return DB::transaction(function () use ($connection, $data, $ranges, $actor, $isDefault): PrebidPriceBucket {
            if ($isDefault) {
                PrebidPriceBucket::withoutGlobalScopes()
                    ->where('organization_id', $connection->organization_id)
                    ->update(['is_default' => false]);
            }

            $bucket = PrebidPriceBucket::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $connection->organization_id, 'code' => (string) str($data['code'])->slug('-')],
                [
                    'name' => trim($data['name']),
                    'currency_code' => strtoupper((string) $data['currency_code']),
                    'granularity' => 'CUSTOM',
                    'ranges' => $ranges,
                    'is_default' => $isDefault,
                    'enabled' => (bool) ($data['enabled'] ?? true),
                    'created_by' => PrebidPriceBucket::withoutGlobalScopes()
                        ->where('organization_id', $connection->organization_id)
                        ->where('code', (string) str($data['code'])->slug('-'))
                        ->value('created_by') ?? $actor->id,
                    'updated_by' => $actor->id,
                ],
            );

            if ($isDefault) {
                PrebidGamTemplate::withoutGlobalScopes()
                    ->where('gam_connection_id', $connection->id)
                    ->update(['prebid_price_bucket_id' => $bucket->id, 'currency_code' => $bucket->currency_code, 'updated_by' => $actor->id]);
            }

            $this->audit->record('prebid.price_bucket.saved', $connection->organization_id, $actor, $bucket, newValues: [
                'gam_connection_id' => $connection->id,
                'code' => $bucket->code,
                'currency' => $bucket->currency_code,
                'values' => count($this->buckets->values($bucket)),
                'is_default' => $bucket->is_default,
            ]);

            return $bucket->refresh();
        });
    }

    public function defaultBucket(GamConnection $connection, User $actor): PrebidPriceBucket
    {
        $existing = PrebidPriceBucket::withoutGlobalScopes()
            ->where('organization_id', $connection->organization_id)
            ->where('enabled', true)
            ->orderByDesc('is_default')
            ->first();
        if ($existing) {
            return $existing;
        }

        return PrebidPriceBucket::withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $connection->organization_id, 'code' => 'standard-usd'],
            [
                'name' => 'Standard USD 0.05',
                'currency_code' => 'USD',
                'granularity' => 'CUSTOM',
                'ranges' => $this->buckets->defaultRanges(),
                'is_default' => true,
                'enabled' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ],
        );
    }

    private function assertConnection(Site $site, ?GamConnection $connection): void
    {
        if ($connection && ! $connection->is_enabled) {
            throw ValidationException::withMessages(['gam_connection_id' => 'The selected GAM connection is disabled.']);
        }
        if ($connection && $connection->organization_id !== $site->organization_id && $connection->type->value === 'PUBLISHER_GAM') {
            throw ValidationException::withMessages(['gam_connection_id' => 'This publisher GAM connection belongs to another organization.']);
        }
    }

    private function assertSameOrganization(string $left, string $right): void
    {
        if ($left !== $right) {
            throw ValidationException::withMessages(['bidder_account_id' => 'The bidder account belongs to another organization.']);
        }
    }
}
