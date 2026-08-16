<?php

namespace App\Observers;

use App\Models\BidderAdsTxtRecord;
use App\Models\BidderSiteMapping;
use App\Models\DemandAdsTxtRecord;
use App\Models\DemandSite;
use App\Models\PlatformAdsTxtRecord;
use App\Models\Publisher;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteServingSetting;
use App\Services\StaticDelivery\SupplyChainStaticPublisher;
use Illuminate\Database\Eloquent\Model;

final class SupplyChainStaticPublicationObserver
{
    public function __construct(private readonly SupplyChainStaticPublisher $publisher) {}

    public function created(Model $model): void
    {
        $this->publisher->queueForModel($model, false, ['event' => 'CREATED']);
    }

    public function updated(Model $model): void
    {
        if (! $this->touchesSupplyChain($model)) {
            return;
        }

        $this->publisher->queueForModel($model, $this->isSafetyRevocation($model), [
            'event' => 'UPDATED',
            'changed' => array_values(array_keys($model->getChanges())),
        ]);
    }

    public function deleted(Model $model): void
    {
        $urgent = $model instanceof SellerDeclaration
            || $model instanceof PlatformAdsTxtRecord
            || $model instanceof BidderAdsTxtRecord
            || $model instanceof DemandAdsTxtRecord;

        $this->publisher->queueForModel($model, $urgent, ['event' => 'DELETED']);
    }

    private function touchesSupplyChain(Model $model): bool
    {
        $fields = match (true) {
            $model instanceof SellerDeclaration => ['seller_id', 'seller_type', 'ads_txt_relationship', 'name', 'domain', 'is_confidential', 'status', 'review_status', 'publisher_id', 'site_id'],
            $model instanceof Publisher => ['legal_name', 'business_domain', 'status', 'supply_chain_review_status', 'supply_chain_reviewed_at', 'supply_chain_reviewed_by'],
            $model instanceof Site => ['primary_domain', 'publisher_id', 'status'],
            $model instanceof SiteDomain => ['domain', 'is_primary', 'verification_status'],
            $model instanceof PlatformAdsTxtRecord => ['advertising_system_domain', 'publisher_account_id', 'relationship', 'certification_authority_id', 'status', 'review_status', 'effective_from', 'effective_to'],
            $model instanceof BidderAdsTxtRecord => ['bidder_account_id', 'site_id', 'advertising_system_domain', 'publisher_account_id', 'relationship', 'certification_authority_id', 'status', 'review_status'],
            $model instanceof BidderSiteMapping => ['bidder_account_id', 'site_id', 'enabled'],
            $model instanceof DemandAdsTxtRecord => ['demand_account_id', 'site_id', 'domain', 'publisher_account_id', 'relationship', 'certification_authority_id', 'status', 'review_status'],
            $model instanceof DemandSite => ['demand_account_id', 'site_id', 'is_enabled', 'approval_status'],
            $model instanceof SiteServingSetting => ['management_role', 'monetization_manager_domain', 'monetization_manager_relationship', 'monetization_manager_country'],
            default => [],
        };

        return $fields !== [] && $model->wasChanged($fields);
    }

    private function isSafetyRevocation(Model $model): bool
    {
        if ($model instanceof SellerDeclaration) {
            return $this->transitionedFromActiveToDisabled($model, 'status');
        }
        if ($model instanceof PlatformAdsTxtRecord || $model instanceof BidderAdsTxtRecord || $model instanceof DemandAdsTxtRecord) {
            return $this->transitionedFromActiveToDisabled($model, 'status');
        }
        if ($model instanceof SiteServingSetting && $model->wasChanged('management_role')) {
            return strtoupper((string) $model->management_role) === 'NONE';
        }

        return false;
    }

    private function transitionedFromActiveToDisabled(Model $model, string $field): bool
    {
        $before = strtoupper((string) $model->getRawOriginal($field));
        $after = strtoupper((string) $model->getAttribute($field));

        return $before === 'ACTIVE' && in_array($after, ['DISABLED', 'INACTIVE', 'REJECTED'], true);
    }
}
