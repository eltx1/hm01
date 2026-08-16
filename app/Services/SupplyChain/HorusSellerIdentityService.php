<?php

namespace App\Services\SupplyChain;

use App\Enums\AccountStatus;
use App\Enums\ContractStatus;
use App\Enums\SellerDeclarationStatus;
use App\Enums\SellerIdentityScope;
use App\Enums\SellerIdentitySource;
use App\Enums\SellerType;
use App\Enums\SupplyChainReviewStatus;
use App\Models\Publisher;
use App\Models\PublisherApplication;
use App\Models\PublisherApplicationDomainClaim;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

final class HorusSellerIdentityService
{
    public const PUBLISHER_ID_PREFIX = 'HMP-';
    public const WEBSITE_ID_PREFIX = 'HMS-';

    /** Backward-compatible Task 33 constant. */
    public const PUBLIC_ID_PREFIX = self::PUBLISHER_ID_PREFIX;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly DomainNormalizer $domains,
    ) {}

    public static function usesReservedNamespace(string $sellerId): bool
    {
        $sellerId = strtoupper(trim($sellerId));

        return str_starts_with($sellerId, self::PUBLISHER_ID_PREFIX)
            || str_starts_with($sellerId, self::WEBSITE_ID_PREFIX);
    }

    public function ensureForPublisher(Publisher $publisher, ?User $actor = null): SellerDeclaration
    {
        return DB::transaction(function () use ($publisher, $actor): SellerDeclaration {
            $lockedPublisher = Publisher::withoutGlobalScopes()->lockForUpdate()->findOrFail($publisher->id);
            $existing = SellerDeclaration::withoutGlobalScopes()
                ->where('publisher_id', $lockedPublisher->id)
                ->where('identity_source', SellerIdentitySource::HorusManaged->value)
                ->where('identity_scope', SellerIdentityScope::Publisher->value)
                ->lockForUpdate()->get();

            if ($existing->count() > 1) {
                throw new LogicException('A Publisher may have only one HORUS_MANAGED HMP identity.');
            }
            if ($existing->isNotEmpty()) {
                return $existing->first();
            }

            $preApproval = $lockedPublisher->status !== AccountStatus::Active;
            $seller = SellerDeclaration::withoutGlobalScopes()->create([
                'organization_id' => $lockedPublisher->organization_id,
                'publisher_id' => $lockedPublisher->id,
                'site_id' => null,
                'publisher_application_domain_claim_id' => null,
                'identity_source' => SellerIdentitySource::HorusManaged,
                'identity_scope' => SellerIdentityScope::Publisher,
                'seller_id' => $this->generatePublicSellerId(self::PUBLISHER_ID_PREFIX),
                'seller_type' => SellerType::Publisher->value,
                'ads_txt_relationship' => 'DIRECT',
                'name' => $this->publisherName($lockedPublisher),
                'domain' => $lockedPublisher->business_domain,
                'is_confidential' => $preApproval,
                'status' => SellerDeclarationStatus::Disabled,
                'review_status' => SupplyChainReviewStatus::ReviewRequired,
                'identity_issued_at' => now(),
                'metadata' => ['lifecycle' => $preApproval ? 'public_application_reserved' : 'publisher_application_approved'],
            ]);

            $this->auditIssued($seller, $actor);

            return $seller;
        });
    }

    /** @return array{publisher: SellerDeclaration, website: SellerDeclaration} */
    public function ensureForApplicationClaim(PublisherApplication $application, PublisherApplicationDomainClaim $claim, ?User $actor = null): array
    {
        return DB::transaction(function () use ($application, $claim, $actor): array {
            $lockedApplication = PublisherApplication::withoutGlobalScopes()->lockForUpdate()->findOrFail($application->id);
            $lockedClaim = PublisherApplicationDomainClaim::query()->lockForUpdate()->findOrFail($claim->id);
            if ($lockedClaim->publisher_application_id !== $lockedApplication->id || $lockedClaim->claim_status !== 'CLAIMED') {
                throw ValidationException::withMessages(['primary_domain' => 'The current application domain claim is not eligible for verification.']);
            }

            $publisher = Publisher::withoutGlobalScopes()->lockForUpdate()->findOrFail($lockedApplication->publisher_id);
            $hmp = $this->ensureForPublisher($publisher, $actor);
            $this->syncReservedPublisherIdentity($hmp, $publisher);

            $existing = SellerDeclaration::withoutGlobalScopes()
                ->where('publisher_application_domain_claim_id', $lockedClaim->id)
                ->where('identity_source', SellerIdentitySource::HorusManaged->value)
                ->where('identity_scope', SellerIdentityScope::Website->value)
                ->lockForUpdate()->get();
            if ($existing->count() > 1) {
                throw new LogicException('An application domain claim may have only one HORUS_MANAGED HMS identity.');
            }

            $hms = $existing->first();
            if (! $hms) {
                $hms = SellerDeclaration::withoutGlobalScopes()->create([
                    'organization_id' => $publisher->organization_id,
                    'publisher_id' => $publisher->id,
                    'site_id' => null,
                    'publisher_application_domain_claim_id' => $lockedClaim->id,
                    'identity_source' => SellerIdentitySource::HorusManaged,
                    'identity_scope' => SellerIdentityScope::Website,
                    'seller_id' => $this->generatePublicSellerId(self::WEBSITE_ID_PREFIX),
                    'seller_type' => SellerType::Publisher->value,
                    'ads_txt_relationship' => 'DIRECT',
                    'name' => $this->publisherName($publisher),
                    'domain' => $publisher->business_domain ?: $lockedClaim->normalized_domain,
                    'is_confidential' => true,
                    'status' => SellerDeclarationStatus::Disabled,
                    'review_status' => SupplyChainReviewStatus::ReviewRequired,
                    'identity_issued_at' => now(),
                    'metadata' => [
                        'lifecycle' => 'public_application_reserved',
                        'normalized_domain' => $lockedClaim->normalized_domain,
                    ],
                ]);
                $this->auditIssued($hms, $actor);
            }

            $lockedClaim->update([
                'publisher_seller_declaration_id' => $hmp->id,
                'website_seller_declaration_id' => $hms->id,
                'verification_requested_at' => $lockedClaim->verification_requested_at ?: now(),
            ]);

            return ['publisher' => $hmp->refresh(), 'website' => $hms->refresh()];
        });
    }

    public function ensureForSite(Site $site, ?User $actor = null): SellerDeclaration
    {
        return DB::transaction(function () use ($site, $actor): SellerDeclaration {
            $lockedSite = Site::withoutGlobalScopes()->lockForUpdate()->findOrFail($site->id);
            $publisher = Publisher::withoutGlobalScopes()->lockForUpdate()->findOrFail($lockedSite->publisher_id);
            $this->ensureForPublisher($publisher, $actor);

            $existing = SellerDeclaration::withoutGlobalScopes()
                ->where('publisher_id', $publisher->id)
                ->where('identity_source', SellerIdentitySource::HorusManaged->value)
                ->where('identity_scope', SellerIdentityScope::Website->value)
                ->where('site_id', $lockedSite->id)
                ->lockForUpdate()->get();
            if ($existing->count() > 1) {
                throw new LogicException('A Site may have only one HORUS_MANAGED HMS identity.');
            }
            if ($existing->isNotEmpty()) {
                return $existing->first();
            }

            $claim = PublisherApplicationDomainClaim::query()
                ->where('normalized_domain', strtolower(rtrim((string) $lockedSite->primary_domain, '.')))
                ->where('verification_status', 'VERIFIED')
                ->whereHas('application', fn ($query) => $query->where('publisher_id', $publisher->id))
                ->latest('verified_at')->lockForUpdate()->first();

            if ($claim?->website_seller_declaration_id) {
                $reserved = SellerDeclaration::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->website_seller_declaration_id);
                if ($reserved->publisher_id !== $publisher->id
                    || $reserved->identity_scope !== SellerIdentityScope::Website
                    || $reserved->identity_source !== SellerIdentitySource::HorusManaged) {
                    throw new LogicException('The verified application HMS identity does not belong to this Publisher.');
                }
                if ($reserved->site_id && $reserved->site_id !== $lockedSite->id) {
                    throw new LogicException('The verified application HMS identity is already attached to another Site.');
                }
                if (! $reserved->site_id) {
                    $metadata = is_array($reserved->metadata) ? $reserved->metadata : [];
                    $metadata['lifecycle'] = 'site_attached';
                    $metadata['site_attached_at'] = now()->toIso8601String();
                    $reserved->update(['site_id' => $lockedSite->id, 'metadata' => $metadata]);
                    $this->audit->record(
                        'supply_chain.horus_website_seller_identity.attached',
                        $reserved->organization_id, $actor, $reserved,
                        oldValues: ['site_id' => null], newValues: ['site_id' => $lockedSite->id, 'seller_id' => $reserved->seller_id],
                    );
                }

                return $reserved->refresh();
            }

            $seller = SellerDeclaration::withoutGlobalScopes()->create([
                'organization_id' => $publisher->organization_id,
                'publisher_id' => $publisher->id,
                'site_id' => $lockedSite->id,
                'publisher_application_domain_claim_id' => null,
                'identity_source' => SellerIdentitySource::HorusManaged,
                'identity_scope' => SellerIdentityScope::Website,
                'seller_id' => $this->generatePublicSellerId(self::WEBSITE_ID_PREFIX),
                'seller_type' => SellerType::Publisher->value,
                'ads_txt_relationship' => 'DIRECT',
                'name' => $this->publisherName($publisher),
                'domain' => $publisher->business_domain ?: $lockedSite->primary_domain,
                'is_confidential' => false,
                'status' => SellerDeclarationStatus::Disabled,
                'review_status' => SupplyChainReviewStatus::ReviewRequired,
                'identity_issued_at' => now(),
                'metadata' => ['lifecycle' => 'site_reserved', 'normalized_domain' => strtolower(rtrim((string) $lockedSite->primary_domain, '.'))],
            ]);
            $this->auditIssued($seller, $actor);

            return $seller;
        });
    }

    public function managedForPublisher(Publisher $publisher): ?SellerDeclaration
    {
        $rows = SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->where('identity_scope', SellerIdentityScope::Publisher->value)
            ->get();
        if ($rows->count() > 1) {
            throw new LogicException('A Publisher has multiple HORUS_MANAGED HMP identities.');
        }

        return $rows->first();
    }

    public function managedForSite(Site $site): ?SellerDeclaration
    {
        $rows = SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $site->publisher_id)
            ->where('site_id', $site->id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->where('identity_scope', SellerIdentityScope::Website->value)->get();
        if ($rows->count() > 1) {
            throw new LogicException('A Site has multiple HORUS_MANAGED HMS identities.');
        }

        return $rows->first();
    }

    public function markApplicationApproved(PublisherApplication $application, ?User $actor = null): void
    {
        foreach ($application->domainClaims()->with(['publisherSeller', 'websiteSeller'])->get() as $claim) {
            foreach ([$claim->publisherSeller, $claim->websiteSeller] as $seller) {
                if (! $seller) {
                    continue;
                }
                $metadata = is_array($seller->metadata) ? $seller->metadata : [];
                $metadata['lifecycle'] = 'publisher_application_approved';
                $metadata['application_approved_at'] = now()->toIso8601String();
                $seller->update(['metadata' => $metadata]);
            }
        }
    }

    public function retireApplicationReservations(PublisherApplication $application, string $reason, ?User $actor = null): void
    {
        foreach ($application->domainClaims()->with(['publisherSeller', 'websiteSeller'])->get() as $claim) {
            foreach ([$claim->publisherSeller, $claim->websiteSeller] as $seller) {
                if (! $seller) {
                    continue;
                }
                $metadata = is_array($seller->metadata) ? $seller->metadata : [];
                $metadata['lifecycle'] = 'application_terminal';
                $metadata['terminal_reason'] = $reason;
                $metadata['terminal_at'] = now()->toIso8601String();
                $seller->update(['status' => SellerDeclarationStatus::Disabled, 'metadata' => $metadata]);
            }
        }
    }

    public function reopenForPublisherIdentityChange(Publisher $publisher, ?User $actor = null): ?SellerDeclaration
    {
        return $this->reopen($publisher, 'PUBLISHER_LEGAL_IDENTITY_CHANGED', $actor, syncIdentity: true);
    }

    public function reopenForCommercialRelationshipChange(Publisher $publisher, ?User $actor = null): ?SellerDeclaration
    {
        return $this->reopen($publisher, 'COMMERCIAL_OR_PAYMENT_RELATIONSHIP_CHANGED', $actor, syncIdentity: true);
    }

    public function disableForUnrepresentedPublisher(Publisher $publisher, ?User $actor = null): ?SellerDeclaration
    {
        $seller = $this->managedForPublisher($publisher);
        if (! $seller || $seller->status === SellerDeclarationStatus::Disabled) {
            return $seller;
        }
        $before = ['status' => $seller->status->value, 'review_status' => $seller->review_status->value];
        $seller->update(['status' => SellerDeclarationStatus::Disabled]);
        SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('identity_source', SellerIdentitySource::HorusManaged->value)
            ->where('identity_scope', SellerIdentityScope::Website->value)
            ->update(['status' => SellerDeclarationStatus::Disabled->value]);
        $this->audit->record(
            'supply_chain.horus_seller_identity.disabled', $seller->organization_id, $actor, $seller,
            oldValues: $before,
            newValues: ['status' => SellerDeclarationStatus::Disabled->value, 'review_status' => $seller->fresh()->review_status->value],
            metadata: ['reason' => 'PUBLISHER_NOT_COMMERCIALLY_REPRESENTED'],
        );

        return $seller->refresh();
    }

    public function reviewConfidentiality(SellerDeclaration $declaration, bool $isConfidential, User $actor): SellerDeclaration
    {
        if (! $actor->isHorusAdministrator()) {
            throw ValidationException::withMessages(['is_confidential' => 'Seller confidentiality requires review by a Horus administrator.']);
        }
        $source = $declaration->identity_source instanceof SellerIdentitySource
            ? $declaration->identity_source : SellerIdentitySource::tryFrom((string) $declaration->identity_source);
        if ($source !== SellerIdentitySource::HorusManaged) {
            throw ValidationException::withMessages(['is_confidential' => 'This reviewed confidentiality lifecycle applies only to HORUS_MANAGED seller identities.']);
        }
        $metadata = is_array($declaration->metadata) ? $declaration->metadata : [];
        if ($isConfidential) {
            $metadata['confidentiality_review'] = ['reviewed_at' => now()->toIso8601String(), 'reviewed_by' => $actor->id];
        } else {
            unset($metadata['confidentiality_review']);
        }
        $before = ['is_confidential' => (bool) $declaration->is_confidential];
        $declaration->update(['is_confidential' => $isConfidential, 'metadata' => $metadata]);
        $this->audit->record(
            'supply_chain.horus_seller_identity.confidentiality_reviewed', $declaration->organization_id, $actor, $declaration,
            oldValues: $before, newValues: ['is_confidential' => $isConfidential],
        );

        return $declaration->refresh();
    }

    public function assertActivationEligible(SellerDeclaration $declaration): void
    {
        $source = $declaration->identity_source instanceof SellerIdentitySource
            ? $declaration->identity_source : SellerIdentitySource::tryFrom((string) $declaration->identity_source);
        if ($source !== SellerIdentitySource::HorusManaged) {
            return;
        }

        $errors = [];
        $publisher = Publisher::withoutGlobalScopes()->find($declaration->publisher_id);
        if (! $publisher) {
            $errors[] = 'The managed seller identity must belong to an existing Publisher.';
        } else {
            if ($publisher->status !== AccountStatus::Active) { $errors[] = 'The Publisher must be ACTIVE before its Horus seller identity can be activated.'; }
            if ($publisher->supply_chain_review_status !== SupplyChainReviewStatus::Verified) { $errors[] = 'The Publisher legal identity and business domain must be VERIFIED before seller activation.'; }
            if (! filled($publisher->business_domain)) { $errors[] = 'A reviewed Publisher business domain is required before seller activation.'; }
            if (trim((string) $publisher->legal_name) === '') { $errors[] = 'A reviewed Publisher legal name is required before seller activation.'; }
            if (trim((string) $declaration->name) !== trim((string) $publisher->legal_name)) { $errors[] = 'The seller name must match the reviewed Publisher legal name.'; }
            if (! $this->domains->same($declaration->domain, $publisher->business_domain)) { $errors[] = 'The seller domain must match the reviewed Publisher business domain.'; }
            $represented = $publisher->contracts()
                ->whereIn('status', [ContractStatus::Signed->value, ContractStatus::Active->value])
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()->toDateString()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()->toDateString()))->exists();
            if (! $represented) { $errors[] = 'A current SIGNED or ACTIVE Publisher contract is required before seller activation.'; }
        }

        $scope = $declaration->identity_scope instanceof SellerIdentityScope
            ? $declaration->identity_scope : SellerIdentityScope::tryFrom((string) $declaration->identity_scope);
        if ($scope === SellerIdentityScope::Publisher && $declaration->site_id !== null) {
            $errors[] = 'The HMP Publisher identity must remain publisher-level.';
        }
        if ($scope === SellerIdentityScope::Website) {
            $site = $declaration->site_id ? Site::withoutGlobalScopes()->find($declaration->site_id) : null;
            if (! $site || $site->publisher_id !== $declaration->publisher_id) {
                $errors[] = 'An HMS Website identity requires its canonical Site before activation.';
            }
            $hmp = $publisher ? $this->managedForPublisher($publisher) : null;
            if (! $hmp || $hmp->status !== SellerDeclarationStatus::Active || $hmp->review_status !== SupplyChainReviewStatus::Verified) {
                $errors[] = 'The Publisher HMP identity must be active and verified before an HMS Website identity can activate.';
            }
        }
        if ((string) $declaration->seller_type !== SellerType::Publisher->value) { $errors[] = 'HORUS_MANAGED identities must use seller_type PUBLISHER.'; }
        if (strtoupper(trim((string) $declaration->ads_txt_relationship)) !== 'DIRECT') { $errors[] = 'HORUS_MANAGED identities must use DIRECT ads.txt relationship.'; }
        if ($declaration->review_status !== SupplyChainReviewStatus::Verified) { $errors[] = 'The seller identity must be VERIFIED by an administrator before activation.'; }
        if ((bool) $declaration->is_confidential && ! $this->hasReviewedConfidentiality($declaration)) { $errors[] = 'Confidential seller publication requires an explicit Horus administrator confidentiality review.'; }

        if ($errors !== []) {
            throw ValidationException::withMessages(['status' => $errors]);
        }
    }

    private function reopen(Publisher $publisher, string $reason, ?User $actor, bool $syncIdentity): ?SellerDeclaration
    {
        $seller = $this->managedForPublisher($publisher);
        if (! $seller) { return null; }
        $before = ['name' => $seller->name, 'domain' => $seller->domain, 'status' => $seller->status->value, 'review_status' => $seller->review_status->value];
        $updates = [
            'status' => SellerDeclarationStatus::Disabled,
            'review_status' => SupplyChainReviewStatus::ReviewRequired,
            'reviewed_at' => null, 'reviewed_by' => null, 'last_verified_at' => null,
        ];
        if ($syncIdentity) {
            $updates['name'] = $this->publisherName($publisher);
            $updates['domain'] = $publisher->business_domain;
        }
        $metadata = is_array($seller->metadata) ? $seller->metadata : [];
        $metadata['review_reopened'] = ['reason' => $reason, 'at' => now()->toIso8601String()];
        $updates['metadata'] = $metadata;
        $seller->update($updates);
        $this->audit->record(
            'supply_chain.horus_seller_identity.review_reopened', $seller->organization_id, $actor, $seller,
            oldValues: $before,
            newValues: ['name' => $seller->fresh()->name, 'domain' => $seller->fresh()->domain, 'status' => SellerDeclarationStatus::Disabled->value, 'review_status' => SupplyChainReviewStatus::ReviewRequired->value],
            metadata: ['reason' => $reason],
        );

        return $seller->refresh();
    }

    private function syncReservedPublisherIdentity(SellerDeclaration $seller, Publisher $publisher): void
    {
        if ($seller->status !== SellerDeclarationStatus::Disabled || $seller->review_status !== SupplyChainReviewStatus::ReviewRequired) {
            return;
        }
        $updates = [];
        $name = $this->publisherName($publisher);
        if ($seller->name !== $name) { $updates['name'] = $name; }
        if ($publisher->business_domain && ! $this->domains->same($seller->domain, $publisher->business_domain)) { $updates['domain'] = $publisher->business_domain; }
        if (! $seller->is_confidential) { $updates['is_confidential'] = true; }
        if ($updates !== []) { $seller->update($updates); }
    }

    private function auditIssued(SellerDeclaration $seller, ?User $actor): void
    {
        $this->audit->record(
            $seller->identity_scope === SellerIdentityScope::Website
                ? 'supply_chain.horus_website_seller_identity.issued'
                : 'supply_chain.horus_seller_identity.issued',
            $seller->organization_id, $actor, $seller,
            newValues: [
                'seller_id' => $seller->seller_id,
                'publisher_id' => $seller->publisher_id,
                'site_id' => $seller->site_id,
                'identity_scope' => $seller->identity_scope->value,
                'identity_source' => SellerIdentitySource::HorusManaged->value,
                'status' => SellerDeclarationStatus::Disabled->value,
                'review_status' => SupplyChainReviewStatus::ReviewRequired->value,
            ],
        );
    }

    private function publisherName(Publisher $publisher): string
    {
        $name = trim((string) $publisher->legal_name);
        if ($name === '') { $name = trim((string) $publisher->display_name); }
        if ($name === '') { throw new LogicException('A Horus seller identity requires a Publisher name.'); }

        return $name;
    }

    private function hasReviewedConfidentiality(SellerDeclaration $declaration): bool
    {
        $review = data_get($declaration->metadata, 'confidentiality_review');
        if (! is_array($review) || ! filled($review['reviewed_at'] ?? null) || ! filled($review['reviewed_by'] ?? null)) { return false; }
        $reviewer = User::with('organization')->find($review['reviewed_by']);

        return $reviewer?->isHorusAdministrator() === true;
    }

    private function generatePublicSellerId(string $prefix): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = $prefix.strtoupper((string) Str::ulid());
            if (! SellerDeclaration::withoutGlobalScopes()->where('seller_id', $candidate)->exists()) { return $candidate; }
        }

        throw new RuntimeException('Unable to allocate a unique Horus public seller ID.');
    }
}
