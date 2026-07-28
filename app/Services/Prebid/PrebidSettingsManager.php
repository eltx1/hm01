<?php

namespace App\Services\Prebid;

use App\Enums\PrebidBidderSequence;
use App\Enums\PrebidPriceGranularity;
use App\Models\BidderAccount;
use App\Models\BidderPlacementMapping;
use App\Models\BidderSiteMapping;
use App\Models\Placement;
use App\Models\PrebidBidder;
use App\Models\PrebidBuild;
use App\Models\PrebidSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrebidSettingsManager
{
    public function __construct(
        private readonly PrebidParameterValidator $parameters,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function ensureForSite(Site $site): PrebidSetting
    {
        return PrebidSetting::withoutGlobalScopes()->firstOrCreate(
            ['site_id' => $site->id],
            [
                'organization_id' => $site->organization_id,
                'prebid_build_id' => PrebidBuild::withoutGlobalScopes()->where('is_active', true)->value('id'),
                'is_enabled' => (bool) $site->prebid_enabled,
                'auction_timeout_ms' => config('prebid.auction_timeout_ms', 1200),
                'price_granularity' => PrebidPriceGranularity::Dense,
                'currency' => config('prebid.currency', 'USD'),
                'bidder_sequence' => PrebidBidderSequence::Random,
                'consent_config' => [
                    'gdpr' => ['cmpApi' => 'iab', 'timeout' => 800, 'defaultGdprScope' => false],
                    'gpp' => ['cmpApi' => 'iab', 'timeout' => 800],
                ],
                'user_sync_config' => ['filterSettings' => ['iframe' => ['bidders' => '*', 'filter' => 'include']]],
                'lazy_loading_enabled' => true,
                'refresh_enabled' => false,
                'timeout_reporting_enabled' => true,
                'gam_fallback_enabled' => true,
                'send_all_bids' => false,
                'debug_enabled' => false,
            ],
        );
    }

    public function updateSettings(Site $site, array $data, User $actor): PrebidSetting
    {
        return DB::transaction(function () use ($site, $data, $actor): PrebidSetting {
            $setting = $this->ensureForSite($site);
            $old = $setting->toArray();
            $setting->update([
                'prebid_build_id' => $data['prebid_build_id'] ?? $setting->prebid_build_id,
                'is_enabled' => (bool) ($data['is_enabled'] ?? $setting->is_enabled),
                'auction_timeout_ms' => (int) ($data['auction_timeout_ms'] ?? $setting->auction_timeout_ms),
                'price_granularity' => isset($data['price_granularity']) ? PrebidPriceGranularity::from($data['price_granularity']) : $setting->price_granularity,
                'currency' => strtoupper($data['currency'] ?? $setting->currency),
                'bidder_sequence' => isset($data['bidder_sequence']) ? PrebidBidderSequence::from($data['bidder_sequence']) : $setting->bidder_sequence,
                'consent_config' => $data['consent_config'] ?? $setting->consent_config,
                'user_sync_config' => $data['user_sync_config'] ?? $setting->user_sync_config,
                'lazy_loading_enabled' => (bool) ($data['lazy_loading_enabled'] ?? $setting->lazy_loading_enabled),
                'refresh_enabled' => (bool) ($data['refresh_enabled'] ?? $setting->refresh_enabled),
                'refresh_interval_seconds' => $data['refresh_interval_seconds'] ?? $setting->refresh_interval_seconds,
                'timeout_reporting_enabled' => (bool) ($data['timeout_reporting_enabled'] ?? $setting->timeout_reporting_enabled),
                'gam_fallback_enabled' => (bool) ($data['gam_fallback_enabled'] ?? $setting->gam_fallback_enabled),
                'send_all_bids' => (bool) ($data['send_all_bids'] ?? $setting->send_all_bids),
                'debug_enabled' => (bool) ($data['debug_enabled'] ?? $setting->debug_enabled),
                'advanced_config' => $data['advanced_config'] ?? $setting->advanced_config,
                'configuration_version' => $setting->configuration_version + 1,
            ]);
            $site->update(['prebid_enabled' => $setting->is_enabled]);
            $this->bumpSiteConfig($site);
            $this->audit->record('prebid.settings.updated', $site->organization_id, $actor, $setting, $old, $setting->fresh()->toArray());

            return $setting->fresh(['build', 'priceBuckets']);
        });
    }

    public function createAccount(PrebidBidder $bidder, array $data, User $actor): BidderAccount
    {
        $bidder->loadMissing('adapter');
        $publicParameters = $this->parameters->validate($bidder->adapter, $data['public_parameters'] ?? []);

        $account = BidderAccount::withoutGlobalScopes()->create([
            'organization_id' => $data['organization_id'],
            'prebid_bidder_id' => $bidder->id,
            'name' => $data['name'],
            'publisher_id' => $data['publisher_id'] ?? null,
            'account_code' => $data['account_code'] ?? null,
            'public_parameters' => $publicParameters,
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'approval_status' => $data['approval_status'] ?? 'APPROVED',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit->record('prebid.bidder_account.created', $account->organization_id, $actor, $account, newValues: [
            'bidder' => $bidder->code,
            'name' => $account->name,
            'public_parameters' => $publicParameters,
        ]);

        return $account->load('bidder.adapter');
    }

    public function assignAccountToSite(BidderAccount $account, Site $site, array $data, User $actor): BidderSiteMapping
    {
        $account->loadMissing('bidder.adapter');
        $this->assertOrganizationCompatibility($account, $site);
        $parameters = $this->parameters->validate($account->bidder->adapter, $data['public_parameters'] ?? []);

        $mapping = BidderSiteMapping::withoutGlobalScopes()->updateOrCreate(
            ['bidder_account_id' => $account->id, 'site_id' => $site->id],
            [
                'organization_id' => $site->organization_id,
                'publisher_id' => $data['publisher_id'] ?? null,
                'public_parameters' => $parameters,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'sequence' => (int) ($data['sequence'] ?? 100),
            ],
        );

        $this->bump($site);
        $this->audit->record('prebid.bidder.assigned_to_site', $site->organization_id, $actor, $mapping, newValues: [
            'bidder_account_id' => $account->id,
            'site_id' => $site->id,
            'enabled' => $mapping->is_enabled,
        ]);

        return $mapping->load('account.bidder.adapter');
    }

    public function assignToPlacement(BidderSiteMapping $siteMapping, Placement $placement, array $data, User $actor): BidderPlacementMapping
    {
        abort_unless($siteMapping->site_id === $placement->site_id, 422, 'Bidder and placement must belong to the same website.');
        $siteMapping->loadMissing('account.bidder.adapter', 'site');
        $parameters = $this->parameters->validate($siteMapping->account->bidder->adapter, $data['public_parameters'] ?? []);

        $mapping = BidderPlacementMapping::withoutGlobalScopes()->updateOrCreate(
            ['bidder_site_mapping_id' => $siteMapping->id, 'placement_id' => $placement->id],
            [
                'organization_id' => $placement->organization_id,
                'placement_id_value' => $data['placement_id_value'] ?? null,
                'public_parameters' => $parameters,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'sequence' => (int) ($data['sequence'] ?? 100),
            ],
        );

        $this->bump($siteMapping->site);
        $this->audit->record('prebid.bidder.assigned_to_placement', $placement->organization_id, $actor, $mapping, newValues: [
            'placement_id' => $placement->id,
            'bidder_site_mapping_id' => $siteMapping->id,
            'enabled' => $mapping->is_enabled,
        ]);

        return $mapping;
    }

    public function setAccountEnabled(BidderAccount $account, bool $enabled, User $actor): BidderAccount
    {
        $account->update(['is_enabled' => $enabled, 'updated_by' => $actor->id]);
        $account->siteMappings()->with('site')->get()->each(fn (BidderSiteMapping $mapping) => $this->bump($mapping->site));
        $this->audit->record('prebid.bidder_account.toggled', $account->organization_id, $actor, $account, newValues: ['is_enabled' => $enabled]);

        return $account->fresh();
    }

    private function assertOrganizationCompatibility(BidderAccount $account, Site $site): void
    {
        if ($account->organization_id === $site->organization_id) {
            return;
        }

        $accountOwner = $account->organization()->withoutGlobalScopes()->first();
        if ($accountOwner?->type?->value !== 'HORUS_MEDIA') {
            throw ValidationException::withMessages([
                'bidder_account' => 'A publisher-owned bidder account cannot be assigned to another organization.',
            ]);
        }
    }

    private function bump(Site $site): void
    {
        $setting = $this->ensureForSite($site);
        $setting->increment('configuration_version');
        $this->bumpSiteConfig($site);
    }

    private function bumpSiteConfig(Site $site): void
    {
        $site->servingSettings()->first()?->increment('configuration_version');
    }
}
