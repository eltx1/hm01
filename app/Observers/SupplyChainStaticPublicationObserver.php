<?php

namespace App\Observers;

use App\Enums\PublisherApplicationStatus;
use App\Enums\SiteManagementRole;
use App\Models\BidderAdsTxtRecord;
use App\Models\BidderSiteMapping;
use App\Models\DemandAdsTxtRecord;
use App\Models\DemandSite;
use App\Models\PlatformAdsTxtRecord;
use App\Models\Publisher;
use App\Models\PublisherApplication;
use App\Models\PublisherApplicationDomainClaim;
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
        if (! $this->createdModelChangesPublicArtifacts($model)) {
            return;
        }

        $this->publisher->queueForModel($model, false, ['event' => 'CREATED']);
    }

    public function updated(Model $model): void
    {
        if (! $this->updateChangesPublicArtifacts($model)) {
            return;
        }

        $this->publisher->queueForModel($model, $this->isSafetyRevocation($model), [
            'event' => 'UPDATED',
            'changed' => array_values(array_keys($model->getChanges())),
        ]);
    }

    public function deleted(Model $model): void
    {
        if (! $this->deletedModelCouldHaveBeenPublic($model)) {
            return;
        }

        $urgent = $model instanceof SellerDeclaration
            || $model instanceof PlatformAdsTxtRecord
            || $model instanceof BidderAdsTxtRecord
            || $model instanceof DemandAdsTxtRecord;

        $this->publisher->queueForModel($model, $urgent, ['event' => 'DELETED']);
    }

    private function createdModelChangesPublicArtifacts(Model $model): bool
    {
        return match (true) {
            $model instanceof PlatformAdsTxtRecord,
            $model instanceof BidderAdsTxtRecord,
            $model instanceof DemandAdsTxtRecord,
            $model instanceof BidderSiteMapping,
            $model instanceof DemandSite => true,
            $model instanceof SellerDeclaration => $this->enumValue($model->status) === 'ACTIVE',
            $model instanceof Site => in_array($this->enumValue($model->status), ['APPROVED', 'ACTIVE'], true),
            default => false,
        };
    }

    private function deletedModelCouldHaveBeenPublic(Model $model): bool
    {
        return match (true) {
            $model instanceof PlatformAdsTxtRecord,
            $model instanceof BidderAdsTxtRecord,
            $model instanceof DemandAdsTxtRecord,
            $model instanceof BidderSiteMapping,
            $model instanceof DemandSite => true,
            $model instanceof SellerDeclaration => $this->enumValue($model->status) === 'ACTIVE',
            $model instanceof Site => in_array($this->enumValue($model->status), ['APPROVED', 'ACTIVE'], true),
            default => false,
        };
    }

    private function updateChangesPublicArtifacts(Model $model): bool
    {
        if ($model instanceof PublisherApplicationDomainClaim) {
            return $this->claimPublicationEligibilityChanged($model);
        }
        if ($model instanceof PublisherApplication) {
            return $this->applicationAuthorizationWasRevoked($model);
        }

        return $this->touchesSupplyChain($model);
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
            $model instanceof SiteServingSetting => ['monetization_manager_role', 'monetization_manager_domain', 'monetization_manager_relationship', 'monetization_manager_country'],
            default => [],
        };

        return $fields !== [] && $model->wasChanged($fields);
    }

    private function isSafetyRevocation(Model $model): bool
    {
        if ($model instanceof PublisherApplicationDomainClaim) {
            return $this->claimWasPublishable($model) && ! $this->claimIsPublishable($model);
        }
        if ($model instanceof PublisherApplication) {
            return $this->applicationAuthorizationWasRevoked($model);
        }
        if ($model instanceof SellerDeclaration) {
            return $this->transitionedFromActiveToDisabled($model, 'status');
        }
        if ($model instanceof PlatformAdsTxtRecord || $model instanceof BidderAdsTxtRecord || $model instanceof DemandAdsTxtRecord) {
            return $this->transitionedFromActiveToDisabled($model, 'status');
        }
        if ($model instanceof SiteServingSetting && $model->wasChanged('monetization_manager_role')) {
            return $this->enumValue($model->monetization_manager_role) === SiteManagementRole::None->value;
        }

        return false;
    }

    private function claimPublicationEligibilityChanged(PublisherApplicationDomainClaim $claim): bool
    {
        return $this->claimWasPublishable($claim) !== $this->claimIsPublishable($claim);
    }

    private function claimWasPublishable(PublisherApplicationDomainClaim $claim): bool
    {
        return strtoupper((string) $claim->getRawOriginal('claim_status')) === 'CLAIMED'
            && strtoupper((string) $claim->getRawOriginal('verification_status')) === 'VERIFIED';
    }

    private function claimIsPublishable(PublisherApplicationDomainClaim $claim): bool
    {
        return $this->enumValue($claim->claim_status) === 'CLAIMED'
            && $this->enumValue($claim->verification_status) === 'VERIFIED';
    }

    private function applicationAuthorizationWasRevoked(PublisherApplication $application): bool
    {
        if (! $application->wasChanged('status')) {
            return false;
        }

        $terminal = [PublisherApplicationStatus::Rejected->value, PublisherApplicationStatus::Withdrawn->value];
        $before = strtoupper((string) $application->getRawOriginal('status'));
        $after = $this->enumValue($application->status);
        if (in_array($before, $terminal, true) || ! in_array($after, $terminal, true)) {
            return false;
        }

        return $application->domainClaims()->where('verification_status', 'VERIFIED')->exists();
    }

    private function transitionedFromActiveToDisabled(Model $model, string $field): bool
    {
        $before = strtoupper((string) $model->getRawOriginal($field));
        $after = $this->enumValue($model->getAttribute($field));

        return $before === 'ACTIVE' && in_array($after, ['DISABLED', 'INACTIVE', 'REJECTED'], true);
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return strtoupper((string) $value->value);
        }

        return strtoupper((string) $value);
    }
}
