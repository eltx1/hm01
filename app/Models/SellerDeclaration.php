<?php

namespace App\Models;

use App\Enums\SellerDeclarationStatus;
use App\Enums\SellerIdentityScope;
use App\Enums\SellerIdentitySource;
use App\Enums\SellerType;
use App\Enums\SupplyChainReviewStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\SupplyChain\DomainNormalizer;
use App\Services\SupplyChain\HorusSellerIdentityService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SellerDeclaration extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'publisher_id', 'site_id', 'publisher_application_domain_claim_id',
        'identity_source', 'identity_scope', 'identity_issued_at',
        'seller_id', 'seller_type', 'ads_txt_relationship', 'name', 'domain',
        'is_confidential', 'status', 'review_status', 'last_verified_at',
        'reviewed_at', 'reviewed_by', 'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (SellerDeclaration $declaration): void {
            $declaration->identity_source ??= SellerIdentitySource::Manual;
            $declaration->identity_scope ??= SellerIdentityScope::Publisher;
            $declaration->review_status ??= SupplyChainReviewStatus::ReviewRequired;
            $declaration->status ??= SellerDeclarationStatus::Disabled;

            $source = $declaration->identity_source instanceof SellerIdentitySource
                ? $declaration->identity_source
                : SellerIdentitySource::from((string) $declaration->identity_source);
            $scope = $declaration->identity_scope instanceof SellerIdentityScope
                ? $declaration->identity_scope
                : SellerIdentityScope::from((string) $declaration->identity_scope);

            if ($source === SellerIdentitySource::HorusManaged) {
                if (! $declaration->publisher_id) {
                    throw new LogicException('A HORUS_MANAGED seller identity must belong to a Publisher.');
                }
                if ($scope === SellerIdentityScope::Publisher) {
                    if ($declaration->site_id !== null || $declaration->publisher_application_domain_claim_id !== null) {
                        throw new LogicException('A Publisher HMP identity must remain publisher-level.');
                    }
                    if (! preg_match('/^HMP-[0-9A-HJKMNP-TV-Z]{26}$/', (string) $declaration->seller_id)) {
                        throw new LogicException('Publisher HORUS_MANAGED seller IDs must use HMP-<ULID>.');
                    }
                } else {
                    if (! preg_match('/^HMS-[0-9A-HJKMNP-TV-Z]{26}$/', (string) $declaration->seller_id)) {
                        throw new LogicException('Website HORUS_MANAGED seller IDs must use HMS-<ULID>.');
                    }
                    if ($declaration->site_id === null && $declaration->publisher_application_domain_claim_id === null) {
                        throw new LogicException('A reserved Website HMS identity requires an application-domain claim until a Site exists.');
                    }
                }
                if ((string) $declaration->seller_type !== SellerType::Publisher->value) {
                    throw new LogicException('HORUS_MANAGED HMP/HMS identities must use seller_type PUBLISHER.');
                }
                if (strtoupper(trim((string) $declaration->ads_txt_relationship)) !== 'DIRECT') {
                    throw new LogicException('HORUS_MANAGED HMP/HMS identities must use DIRECT ads.txt relationship.');
                }
                if (SellerDeclaration::withoutGlobalScopes()->where('seller_id', $declaration->seller_id)->exists()) {
                    throw new LogicException('A Horus public seller ID may be issued only once.');
                }
                $declaration->identity_issued_at ??= now();
            } elseif (HorusSellerIdentityService::usesReservedNamespace((string) $declaration->seller_id)) {
                throw new LogicException('The HMP-/HMS- seller ID namespaces are reserved for HORUS_MANAGED identities.');
            }
        });

        static::updating(function (SellerDeclaration $declaration): void {
            $originalSource = SellerIdentitySource::tryFrom((string) $declaration->getRawOriginal('identity_source'))
                ?? SellerIdentitySource::Manual;

            if ($declaration->isDirty('identity_source')) {
                throw new LogicException('Seller identity source is immutable after declaration creation.');
            }

            if ($originalSource !== SellerIdentitySource::HorusManaged) {
                if ($declaration->isDirty('seller_id') && HorusSellerIdentityService::usesReservedNamespace((string) $declaration->seller_id)) {
                    throw new LogicException('The HMP-/HMS- seller ID namespaces are reserved for HORUS_MANAGED identities.');
                }

                return;
            }

            if ($declaration->isDirty(['seller_id', 'publisher_id', 'identity_scope', 'publisher_application_domain_claim_id', 'identity_issued_at'])) {
                throw new LogicException('A HORUS_MANAGED public seller identity cannot be reassigned or reissued.');
            }

            if ($declaration->isDirty('site_id')) {
                $scope = SellerIdentityScope::tryFrom((string) $declaration->getRawOriginal('identity_scope'));
                $originalSite = $declaration->getRawOriginal('site_id');
                if ($scope !== SellerIdentityScope::Website || $originalSite !== null || $declaration->site_id === null) {
                    throw new LogicException('A Website HMS identity may be attached to its canonical Site once and can never be reassigned.');
                }
                $site = Site::withoutGlobalScopes()->find($declaration->site_id);
                if (! $site || $site->publisher_id !== $declaration->publisher_id) {
                    throw new LogicException('The Website HMS identity may only attach to a Site owned by the same Publisher.');
                }
                if ($declaration->publisher_application_domain_claim_id) {
                    $claim = PublisherApplicationDomainClaim::withoutGlobalScopes()->find($declaration->publisher_application_domain_claim_id);
                    if (! $claim || $claim->verification_status !== 'VERIFIED'
                        || strtolower(rtrim((string) $claim->normalized_domain, '.')) !== strtolower(rtrim((string) $site->primary_domain, '.'))) {
                        throw new LogicException('A reserved HMS identity can only attach to its verified matching application domain.');
                    }
                }
            }

            if ($declaration->isDirty(['name', 'domain', 'is_confidential'])) {
                $declaration->status = SellerDeclarationStatus::Disabled;
                $declaration->review_status = SupplyChainReviewStatus::ReviewRequired;
                $declaration->reviewed_at = null;
                $declaration->reviewed_by = null;
                $declaration->last_verified_at = null;
            }

            if ($declaration->isDirty('status') && $declaration->status === SellerDeclarationStatus::Active) {
                app(HorusSellerIdentityService::class)->assertActivationEligible($declaration);
            }
        });

        static::deleting(function (SellerDeclaration $declaration): void {
            $source = $declaration->identity_source instanceof SellerIdentitySource
                ? $declaration->identity_source
                : SellerIdentitySource::tryFrom((string) $declaration->identity_source);

            if ($source === SellerIdentitySource::HorusManaged) {
                throw new LogicException('HORUS_MANAGED seller identities are permanent records and cannot be deleted or recycled.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'identity_source' => SellerIdentitySource::class,
            'identity_scope' => SellerIdentityScope::class,
            'identity_issued_at' => 'datetime',
            'is_confidential' => 'boolean',
            'status' => SellerDeclarationStatus::class,
            'review_status' => SupplyChainReviewStatus::class,
            'last_verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected function domain(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => app(DomainNormalizer::class)->normalize($value));
    }

    public function publisher(): BelongsTo { return $this->belongsTo(Publisher::class); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function applicationDomainClaim(): BelongsTo { return $this->belongsTo(PublisherApplicationDomainClaim::class, 'publisher_application_domain_claim_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
