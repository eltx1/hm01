<?php

namespace App\Models;

use App\Enums\SellerDeclarationStatus;
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
        'organization_id', 'publisher_id', 'site_id', 'identity_source', 'identity_issued_at',
        'seller_id', 'seller_type', 'ads_txt_relationship', 'name', 'domain',
        'is_confidential', 'status', 'review_status', 'last_verified_at',
        'reviewed_at', 'reviewed_by', 'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (SellerDeclaration $declaration): void {
            $declaration->identity_source ??= SellerIdentitySource::Manual;
            $declaration->review_status ??= SupplyChainReviewStatus::ReviewRequired;
            $declaration->status ??= SellerDeclarationStatus::Disabled;

            $source = $declaration->identity_source instanceof SellerIdentitySource
                ? $declaration->identity_source
                : SellerIdentitySource::from((string) $declaration->identity_source);

            if ($source === SellerIdentitySource::HorusManaged) {
                if (! $declaration->publisher_id || $declaration->site_id !== null) {
                    throw new LogicException('A HORUS_MANAGED seller identity must be publisher-level and have site_id null.');
                }
                if (! preg_match('/^HMP-[0-9A-HJKMNP-TV-Z]{26}$/', (string) $declaration->seller_id)) {
                    throw new LogicException('HORUS_MANAGED seller IDs must use the canonical HMP-<ULID> public format.');
                }
                if ((string) $declaration->seller_type !== SellerType::Publisher->value) {
                    throw new LogicException('The default HORUS_MANAGED seller identity must use seller_type PUBLISHER.');
                }
                if (strtoupper(trim((string) $declaration->ads_txt_relationship)) !== 'DIRECT') {
                    throw new LogicException('The default HORUS_MANAGED Publisher identity must use DIRECT ads.txt relationship.');
                }
                if (SellerDeclaration::withoutGlobalScopes()->where('seller_id', $declaration->seller_id)->exists()) {
                    throw new LogicException('A Horus public seller ID may be issued only once.');
                }
                $declaration->identity_issued_at ??= now();
            } elseif (str_starts_with(strtoupper((string) $declaration->seller_id), HorusSellerIdentityService::PUBLIC_ID_PREFIX)) {
                throw new LogicException('The HMP- seller ID namespace is reserved for HORUS_MANAGED identities.');
            }
        });

        static::updating(function (SellerDeclaration $declaration): void {
            $originalSource = SellerIdentitySource::tryFrom((string) $declaration->getRawOriginal('identity_source'))
                ?? SellerIdentitySource::Manual;

            if ($declaration->isDirty('identity_source')) {
                throw new LogicException('Seller identity source is immutable after declaration creation.');
            }

            if ($originalSource !== SellerIdentitySource::HorusManaged) {
                if ($declaration->isDirty('seller_id')
                    && str_starts_with(strtoupper((string) $declaration->seller_id), HorusSellerIdentityService::PUBLIC_ID_PREFIX)) {
                    throw new LogicException('The HMP- seller ID namespace is reserved for HORUS_MANAGED identities.');
                }

                return;
            }

            if ($declaration->isDirty(['seller_id', 'publisher_id', 'site_id', 'identity_issued_at'])) {
                throw new LogicException('A HORUS_MANAGED public seller identity cannot be reassigned or reissued.');
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
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
