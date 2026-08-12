<?php

namespace App\Services\Prebid;

use App\Models\BidderAccount;
use App\Models\BidderPlacementMapping;
use App\Models\BidderSiteMapping;
use App\Models\GamConnection;
use App\Models\Placement;
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

final class PrebidManager
{
    public function __construct(private readonly AuditRecorder $audit)
    {
    }

    public function settingsFor(GamConnection $connection): PrebidSetting
    {
        $settings = PrebidSetting::withoutGlobalScopes()->firstOrCreate(
            [
                'scope' => PrebidSetting::SCOPE_GAM_CONNECTION,
                'gam_connection_id' => $connection->id,
            ],
            $this->defaultSettings($connection->organization_id) + ['site_id' => null],
        );

        // Preserve the established GAM setup objects and price buckets exactly.
        if (! PrebidPriceBucket::withoutGlobalScopes()->where('gam_connection_id', $connection->id)->exists()) {
            foreach ([
                ['code' => 'low', 'minimum' => 0, 'maximum' => 5, 'increment' => .05, 'precision' => 2, 'sort_order' => 10],
                ['code' => 'mid', 'minimum' => 5, 'maximum' => 10, 'increment' => .10, 'precision' => 2, 'sort_order' => 20],
                ['code' => 'high', 'minimum' => 10, 'maximum' => 20, 'increment' => .50, 'precision' => 2, 'sort_order' => 30],
            ] as $bucket) {
                PrebidPriceBucket::withoutGlobalScopes()->create($bucket + [
                    'organization_id' => $connection->organization_id,
                    'gam_connection_id' => $connection->id,
                    'enabled' => true,
                ]);
            }
        }

        PrebidGamTemplate::withoutGlobalScopes()->firstOrCreate(
            ['gam_connection_id' => $connection->id, 'name' => 'default'],
            [
                'organization_id' => $connection->organization_id,
                'creative_snippet' => '<script>var w=window.parent;w.pbjs=w.pbjs||{};w.pbjs.que=w.pbjs.que||[];w.pbjs.que.push(function(){w.pbjs.renderAd(document,\'%%PATTERN:hb_adid%%\');});</script>',
                'targeting' => ['priceKey' => 'hb_pb'],
                'enabled' => true,
            ],
        );

        return $settings;
    }

    public function settingsForSite(Site $site): PrebidSetting
    {
        return PrebidSetting::withoutGlobalScopes()->firstOrCreate(
            [
                'scope' => PrebidSetting::SCOPE_SITE_STANDALONE,
                'site_id' => $site->id,
            ],
            $this->defaultSettings($site->organization_id, standalone: true) + ['gam_connection_id' => null],
        );
    }

    public function updateSettings(GamConnection $connection, array $data, User $actor): PrebidSetting
    {
        return $this->updateRuntimeSettings(
            $this->settingsFor($connection),
            $data,
            $actor,
            $connection->organization_id,
            'prebid.settings.updated',
            allowGamFallback: true,
        );
    }

    public function updateStandaloneSettings(Site $site, array $data, User $actor): PrebidSetting
    {
        return $this->updateRuntimeSettings(
            $this->settingsForSite($site),
            $data,
            $actor,
            $site->organization_id,
            'prebid.standalone_settings.updated',
            allowGamFallback: false,
        );
    }

    private function updateRuntimeSettings(
        PrebidSetting $settings,
        array $data,
        User $actor,
        string $organizationId,
        string $auditAction,
        bool $allowGamFallback,
    ): PrebidSetting {
        $before = $settings->toArray();
        $settings->update([
            'prebid_build_id' => $data['prebid_build_id'] ?? $settings->prebid_build_id,
            'enabled' => (bool) ($data['enabled'] ?? false),
            'auction_timeout_ms' => max(100, min(5000, (int) ($data['auction_timeout_ms'] ?? 1200))),
            'price_granularity' => (string) ($data['price_granularity'] ?? 'medium'),
            'currency' => strtoupper((string) ($data['currency'] ?? 'USD')),
            'bidder_sequence' => (string) ($data['bidder_sequence'] ?? 'fixed'),
            'consent_behavior' => $data['consent_behavior'] ?? [],
            'lazy_loading' => $data['lazy_loading'] ?? ['enabled' => true],
            'refresh_behavior' => $data['refresh_behavior'] ?? ['enabled' => true, 'minimumIntervalSeconds' => 30],
            'bidder_timeout_reporting' => (bool) ($data['bidder_timeout_reporting'] ?? false),
            'gam_fallback' => $allowGamFallback && (bool) ($data['gam_fallback'] ?? true),
            'configuration' => $data['configuration'] ?? [],
            'updated_by' => $actor->id,
        ]);
        $this->audit->record($auditAction, $organizationId, $actor, $settings, $before, $settings->fresh()->toArray());

        return $settings->fresh();
    }

    private function defaultSettings(string $organizationId, bool $standalone = false): array
    {
        $build = PrebidBuild::query()->where('is_active', true)->latest('built_at')->first();

        return [
            'organization_id' => $organizationId,
            'prebid_build_id' => $build?->id,
            'enabled' => false,
            'auction_timeout_ms' => config('prebid.default_timeout_ms', 1200),
            'price_granularity' => 'medium',
            'currency' => config('prebid.default_currency', 'USD'),
            'bidder_sequence' => 'fixed',
            'consent_behavior' => [
                'gdpr' => ['cmpApi' => 'iab', 'timeout' => 800, 'defaultGdprScope' => true],
                'gpp' => ['cmpApi' => 'iab', 'timeout' => 800],
            ],
            'lazy_loading' => ['enabled' => true],
            'refresh_behavior' => ['enabled' => true, 'minimumIntervalSeconds' => 30],
            'bidder_timeout_reporting' => true,
            'gam_fallback' => ! $standalone,
            'configuration' => $standalone ? [
                'standalone' => [
                    'supportedMediaTypes' => ['banner'],
                    'suppressExpiredRender' => true,
                    'allowTopWindowRenderers' => false,
                ],
            ] : [],
        ];
    }

    public function addAccount(PrebidBidder $bidder, array $data, User $actor): BidderAccount
    {
        $bidder->loadMissing('adapter');
        $parameters = $this->validatedParameters($bidder, $data['public_parameters'] ?? []);

        return DB::transaction(function () use ($bidder, $data, $actor, $parameters): BidderAccount {
            $account = BidderAccount::withoutGlobalScopes()->updateOrCreate(
                [
                    'organization_id' => $actor->organization_id,
                    'prebid_bidder_id' => $bidder->id,
                    'name' => trim((string) $data['name']),
                ],
                [
                    'publisher_id' => filled($data['publisher_id'] ?? null) ? trim((string) $data['publisher_id']) : null,
                    'public_parameters' => $parameters,
                    'enabled' => (bool) ($data['enabled'] ?? true),
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );
            $this->audit->record('prebid.bidder_account.saved', $actor->organization_id, $actor, $account, [], $account->toArray());

            return $account;
        });
    }

    public function assignToSite(BidderAccount $account, Site $site, array $data, User $actor): BidderSiteMapping
    {
        $account->loadMissing('bidder.adapter');
        $parameters = $this->validatedParameters($account->bidder, $data['public_parameters'] ?? []);

        $mapping = BidderSiteMapping::withoutGlobalScopes()->updateOrCreate(
            ['bidder_account_id' => $account->id, 'site_id' => $site->id],
            [
                'organization_id' => $site->organization_id,
                'public_parameters' => $parameters,
                'enabled' => (bool) ($data['enabled'] ?? true),
                'sequence' => (int) ($data['sequence'] ?? 0),
            ],
        );
        $this->audit->record('prebid.bidder_site_mapping.saved', $site->organization_id, $actor, $mapping, [], $mapping->toArray());

        return $mapping;
    }

    public function assignToPlacement(BidderSiteMapping $siteMapping, Placement $placement, array $data, User $actor): BidderPlacementMapping
    {
        $siteMapping->loadMissing('account.bidder.adapter');
        $parameters = $this->validatedParameters($siteMapping->account->bidder, $data['public_parameters'] ?? []);

        $mapping = BidderPlacementMapping::withoutGlobalScopes()->updateOrCreate(
            ['bidder_site_mapping_id' => $siteMapping->id, 'placement_id' => $placement->id],
            [
                'organization_id' => $placement->organization_id,
                'placement_id_value' => filled($data['placement_id_value'] ?? null) ? trim((string) $data['placement_id_value']) : null,
                'public_parameters' => $parameters,
                'enabled' => (bool) ($data['enabled'] ?? true),
                'sequence' => (int) ($data['sequence'] ?? 0),
            ],
        );
        $this->audit->record('prebid.bidder_placement_mapping.saved', $placement->organization_id, $actor, $mapping, [], $mapping->toArray());

        return $mapping;
    }

    public function toggle(BidderAccount|BidderSiteMapping|BidderPlacementMapping $model, bool $enabled, User $actor): void
    {
        $before = $model->toArray();
        $model->update(['enabled' => $enabled]);
        $this->audit->record('prebid.mapping.toggled', $model->organization_id, $actor, $model, $before, $model->fresh()->toArray());
    }

    private function validatedParameters(PrebidBidder $bidder, array $parameters): array
    {
        $allowed = collect($bidder->adapter->required_public_parameters ?? [])
            ->merge($bidder->adapter->optional_public_parameters ?? [])
            ->filter()
            ->unique()
            ->values();
        $unknown = collect(array_keys($parameters))->diff($allowed);
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'public_parameters' => 'Unsupported public parameters for '.$bidder->code.': '.$unknown->implode(', '),
            ]);
        }

        return collect($parameters)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => is_scalar($value) ? (string) $value : $value)
            ->all();
    }
}
