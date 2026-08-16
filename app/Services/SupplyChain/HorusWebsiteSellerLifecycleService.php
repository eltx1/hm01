<?php

namespace App\Services\SupplyChain;

use App\Enums\SellerDeclarationStatus;
use App\Enums\SellerIdentityScope;
use App\Enums\SellerIdentitySource;
use App\Enums\SupplyChainReviewStatus;
use App\Models\Publisher;
use App\Models\SellerDeclaration;
use App\Models\User;
use App\Services\Audit\AuditRecorder;

final class HorusWebsiteSellerLifecycleService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function reopenForPublisherIdentityChange(Publisher $publisher, ?User $actor = null): void
    {
        $this->reopen($publisher, 'PUBLISHER_LEGAL_IDENTITY_CHANGED', $actor, true);
    }

    public function reopenForCommercialRelationshipChange(Publisher $publisher, ?User $actor = null): void
    {
        $this->reopen($publisher, 'COMMERCIAL_OR_PAYMENT_RELATIONSHIP_CHANGED', $actor, false);
    }

    private function reopen(Publisher $publisher, string $reason, ?User $actor, bool $syncIdentity): void
    {
        $sellers = SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->where('identity_scope', SellerIdentityScope::Website->value)
            ->get();

        foreach ($sellers as $seller) {
            $before = [
                'name' => $seller->name,
                'domain' => $seller->domain,
                'status' => $seller->status->value,
                'review_status' => $seller->review_status->value,
            ];
            $metadata = is_array($seller->metadata) ? $seller->metadata : [];
            $metadata['review_reopened'] = ['reason' => $reason, 'at' => now()->toIso8601String()];
            $updates = [
                'status' => SellerDeclarationStatus::Disabled,
                'review_status' => SupplyChainReviewStatus::ReviewRequired,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'last_verified_at' => null,
                'metadata' => $metadata,
            ];
            if ($syncIdentity) {
                $updates['name'] = trim((string) $publisher->legal_name) !== '' ? $publisher->legal_name : $publisher->display_name;
                $updates['domain'] = $publisher->business_domain;
            }
            $seller->update($updates);
            $this->audit->record(
                'supply_chain.horus_website_seller_identity.review_reopened',
                $seller->organization_id,
                $actor,
                $seller,
                oldValues: $before,
                newValues: [
                    'name' => $seller->fresh()->name,
                    'domain' => $seller->fresh()->domain,
                    'status' => SellerDeclarationStatus::Disabled->value,
                    'review_status' => SupplyChainReviewStatus::ReviewRequired->value,
                ],
                metadata: ['reason' => $reason],
            );
        }
    }
}
