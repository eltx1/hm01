<?php

namespace App\Services\SupplyChain;

use App\Enums\AccountStatus;
use App\Enums\ContractStatus;
use App\Enums\SellerDeclarationStatus;
use App\Enums\SellerIdentitySource;
use App\Enums\SellerType;
use App\Enums\SupplyChainReviewStatus;
use App\Models\Publisher;
use App\Models\SellerDeclaration;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

final class HorusSellerIdentityService
{
    public const PUBLIC_ID_PREFIX = 'HMP-';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly DomainNormalizer $domains,
    ) {}

    public function ensureForPublisher(Publisher $publisher, ?User $actor = null): SellerDeclaration
    {
        return DB::transaction(function () use ($publisher, $actor): SellerDeclaration {
            $lockedPublisher = Publisher::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($publisher->id);

            $existing = SellerDeclaration::withoutGlobalScopes()
                ->where('publisher_id', $lockedPublisher->id)
                ->where('identity_source', SellerIdentitySource::HorusManaged->value)
                ->whereNull('site_id')
                ->lockForUpdate()
                ->get();

            if ($existing->count() > 1) {
                throw new LogicException('A Publisher may have only one default HORUS_MANAGED seller identity.');
            }

            if ($existing->isNotEmpty()) {
                return $existing->first();
            }

            $seller = SellerDeclaration::withoutGlobalScopes()->create([
                'organization_id' => $lockedPublisher->organization_id,
                'publisher_id' => $lockedPublisher->id,
                'site_id' => null,
                'identity_source' => SellerIdentitySource::HorusManaged,
                'seller_id' => $this->generatePublicSellerId(),
                'seller_type' => SellerType::Publisher->value,
                'ads_txt_relationship' => 'DIRECT',
                'name' => trim((string) $lockedPublisher->legal_name),
                'domain' => $lockedPublisher->business_domain,
                'is_confidential' => false,
                'status' => SellerDeclarationStatus::Disabled,
                'review_status' => SupplyChainReviewStatus::ReviewRequired,
                'identity_issued_at' => now(),
                'metadata' => [
                    'lifecycle' => 'publisher_application_approved',
                ],
            ]);

            $this->audit->record(
                'supply_chain.horus_seller_identity.issued',
                $seller->organization_id,
                $actor,
                $seller,
                newValues: [
                    'seller_id' => $seller->seller_id,
                    'publisher_id' => $seller->publisher_id,
                    'identity_source' => SellerIdentitySource::HorusManaged->value,
                    'status' => SellerDeclarationStatus::Disabled->value,
                    'review_status' => SupplyChainReviewStatus::ReviewRequired->value,
                ],
            );

            return $seller;
        });
    }

    public function reopenForPublisherIdentityChange(Publisher $publisher, ?User $actor = null): ?SellerDeclaration
    {
        return $this->reopen(
            $publisher,
            'PUBLISHER_LEGAL_IDENTITY_CHANGED',
            $actor,
            syncIdentity: true,
        );
    }

    public function reopenForCommercialRelationshipChange(Publisher $publisher, ?User $actor = null): ?SellerDeclaration
    {
        return $this->reopen(
            $publisher,
            'COMMERCIAL_OR_PAYMENT_RELATIONSHIP_CHANGED',
            $actor,
            syncIdentity: true,
        );
    }

    public function disableForUnrepresentedPublisher(Publisher $publisher, ?User $actor = null): ?SellerDeclaration
    {
        $seller = $this->managedForPublisher($publisher);
        if (! $seller || $seller->status === SellerDeclarationStatus::Disabled) {
            return $seller;
        }

        $before = [
            'status' => $seller->status->value,
            'review_status' => $seller->review_status->value,
        ];
        $seller->update(['status' => SellerDeclarationStatus::Disabled]);

        $this->audit->record(
            'supply_chain.horus_seller_identity.disabled',
            $seller->organization_id,
            $actor,
            $seller,
            oldValues: $before,
            newValues: [
                'status' => SellerDeclarationStatus::Disabled->value,
                'review_status' => $seller->fresh()->review_status->value,
            ],
            metadata: ['reason' => 'PUBLISHER_NOT_COMMERCIALLY_REPRESENTED'],
        );

        return $seller->refresh();
    }

    public function reviewConfidentiality(
        SellerDeclaration $declaration,
        bool $isConfidential,
        User $actor,
    ): SellerDeclaration {
        if (! $actor->isHorusAdministrator()) {
            throw ValidationException::withMessages([
                'is_confidential' => 'Seller confidentiality requires review by a Horus administrator.',
            ]);
        }

        $source = $declaration->identity_source instanceof SellerIdentitySource
            ? $declaration->identity_source
            : SellerIdentitySource::tryFrom((string) $declaration->identity_source);

        if ($source !== SellerIdentitySource::HorusManaged) {
            throw ValidationException::withMessages([
                'is_confidential' => 'This reviewed confidentiality lifecycle applies only to HORUS_MANAGED seller identities.',
            ]);
        }

        $metadata = is_array($declaration->metadata) ? $declaration->metadata : [];
        if ($isConfidential) {
            $metadata['confidentiality_review'] = [
                'reviewed_at' => now()->toIso8601String(),
                'reviewed_by' => $actor->id,
            ];
        } else {
            unset($metadata['confidentiality_review']);
        }

        $before = ['is_confidential' => (bool) $declaration->is_confidential];
        $declaration->update([
            'is_confidential' => $isConfidential,
            'metadata' => $metadata,
        ]);

        $this->audit->record(
            'supply_chain.horus_seller_identity.confidentiality_reviewed',
            $declaration->organization_id,
            $actor,
            $declaration,
            oldValues: $before,
            newValues: ['is_confidential' => $isConfidential],
        );

        return $declaration->refresh();
    }

    public function assertActivationEligible(SellerDeclaration $declaration): void
    {
        $source = $declaration->identity_source instanceof SellerIdentitySource
            ? $declaration->identity_source
            : SellerIdentitySource::tryFrom((string) $declaration->identity_source);

        if ($source !== SellerIdentitySource::HorusManaged) {
            return;
        }

        $errors = [];
        $publisher = Publisher::withoutGlobalScopes()->find($declaration->publisher_id);

        if (! $publisher) {
            $errors[] = 'The managed seller identity must belong to an existing Publisher.';
        } else {
            if ($publisher->status !== AccountStatus::Active) {
                $errors[] = 'The Publisher must be ACTIVE before its Horus seller identity can be activated.';
            }
            if ($publisher->supply_chain_review_status !== SupplyChainReviewStatus::Verified) {
                $errors[] = 'The Publisher legal identity and business domain must be VERIFIED before seller activation.';
            }
            if (! filled($publisher->business_domain)) {
                $errors[] = 'A reviewed Publisher business domain is required before seller activation.';
            }
            if (trim((string) $publisher->legal_name) === '') {
                $errors[] = 'A reviewed Publisher legal name is required before seller activation.';
            }
            if (trim((string) $declaration->name) !== trim((string) $publisher->legal_name)) {
                $errors[] = 'The seller name must match the reviewed Publisher legal name.';
            }
            if (! $this->domains->same($declaration->domain, $publisher->business_domain)) {
                $errors[] = 'The seller domain must match the reviewed Publisher business domain.';
            }

            $represented = $publisher->contracts()
                ->whereIn('status', [ContractStatus::Signed->value, ContractStatus::Active->value])
                ->where(function ($query): void {
                    $query->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()->toDateString());
                })
                ->where(function ($query): void {
                    $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()->toDateString());
                })
                ->exists();

            if (! $represented) {
                $errors[] = 'A current SIGNED or ACTIVE Publisher contract is required before seller activation.';
            }
        }

        if ($declaration->site_id !== null) {
            $errors[] = 'The default HORUS_MANAGED Publisher seller identity must be publisher-level (site_id null).';
        }
        if ((string) $declaration->seller_type !== SellerType::Publisher->value) {
            $errors[] = 'The default HORUS_MANAGED seller identity must use seller_type PUBLISHER.';
        }
        if (strtoupper(trim((string) $declaration->ads_txt_relationship)) !== 'DIRECT') {
            $errors[] = 'The default HORUS_MANAGED Publisher identity must use DIRECT ads.txt relationship.';
        }
        if ($declaration->review_status !== SupplyChainReviewStatus::Verified) {
            $errors[] = 'The seller identity must be VERIFIED by an administrator before activation.';
        }
        if ((bool) $declaration->is_confidential && ! $this->hasReviewedConfidentiality($declaration)) {
            $errors[] = 'Confidential seller publication requires an explicit Horus administrator confidentiality review.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['status' => $errors]);
        }
    }

    public function managedForPublisher(Publisher $publisher): ?SellerDeclaration
    {
        $rows = SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->whereNull('site_id')
            ->get();

        if ($rows->count() > 1) {
            throw new LogicException('A Publisher has multiple default HORUS_MANAGED seller identities.');
        }

        return $rows->first();
    }

    private function reopen(
        Publisher $publisher,
        string $reason,
        ?User $actor,
        bool $syncIdentity,
    ): ?SellerDeclaration {
        $seller = $this->managedForPublisher($publisher);
        if (! $seller) {
            return null;
        }

        $before = [
            'name' => $seller->name,
            'domain' => $seller->domain,
            'status' => $seller->status->value,
            'review_status' => $seller->review_status->value,
        ];
        $updates = [
            'status' => SellerDeclarationStatus::Disabled,
            'review_status' => SupplyChainReviewStatus::ReviewRequired,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'last_verified_at' => null,
        ];
        if ($syncIdentity) {
            $updates['name'] = trim((string) $publisher->legal_name);
            $updates['domain'] = $publisher->business_domain;
        }

        $metadata = is_array($seller->metadata) ? $seller->metadata : [];
        $metadata['review_reopened'] = [
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ];
        $updates['metadata'] = $metadata;

        $seller->update($updates);

        $this->audit->record(
            'supply_chain.horus_seller_identity.review_reopened',
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

        return $seller->refresh();
    }

    private function hasReviewedConfidentiality(SellerDeclaration $declaration): bool
    {
        $review = data_get($declaration->metadata, 'confidentiality_review');
        if (! is_array($review) || ! filled($review['reviewed_at'] ?? null) || ! filled($review['reviewed_by'] ?? null)) {
            return false;
        }

        $reviewer = User::with('organization')->find($review['reviewed_by']);

        return $reviewer?->isHorusAdministrator() === true;
    }

    private function generatePublicSellerId(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = self::PUBLIC_ID_PREFIX.strtoupper((string) Str::ulid());
            $exists = SellerDeclaration::withoutGlobalScopes()
                ->where('seller_id', $candidate)
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique Horus public seller ID.');
    }
}
