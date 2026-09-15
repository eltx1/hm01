<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\SupplyChain\DomainNormalizer;
use App\Services\SupplyChain\HorusSellerIdentityService;
use App\Services\SupplyChain\HorusWebsiteSellerLifecycleService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Publisher extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'legal_name', 'display_name', 'business_domain',
        'referral_code', 'referred_by_publisher_id', 'affiliate_commission_override_bp', 'referred_at',
        'supply_chain_review_status', 'supply_chain_reviewed_at', 'supply_chain_reviewed_by',
        'status', 'billing_email', 'logo_path', 'dashboard_title', 'primary_color',
        'internal_notes', 'onboarding_step', 'onboarding_submitted_at',
    ];

    protected $hidden = ['internal_notes'];

    protected static function booted(): void
    {
        static::creating(function (Publisher $publisher): void {
            $publisher->supply_chain_review_status ??= SupplyChainReviewStatus::ReviewRequired;

            if (blank($publisher->referral_code)) {
                do {
                    $code = 'HM'.Str::upper(Str::random(18));
                } while (static::withoutGlobalScopes()->where('referral_code', $code)->exists());

                $publisher->referral_code = $code;
            }
        });

        static::updating(function (Publisher $publisher): void {
            if ($publisher->isDirty(['legal_name', 'business_domain'])) {
                $publisher->supply_chain_review_status = SupplyChainReviewStatus::ReviewRequired;
                $publisher->supply_chain_reviewed_at = null;
                $publisher->supply_chain_reviewed_by = null;
            }
        });

        static::updated(function (Publisher $publisher): void {
            $identities = app(HorusSellerIdentityService::class);

            if ($publisher->wasChanged(['legal_name', 'business_domain'])) {
                $identities->reopenForPublisherIdentityChange($publisher);
                app(HorusWebsiteSellerLifecycleService::class)->reopenForPublisherIdentityChange($publisher);
            }

            if ($publisher->wasChanged('status') && $publisher->status !== AccountStatus::Active) {
                $identities->disableForUnrepresentedPublisher($publisher);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'supply_chain_review_status' => SupplyChainReviewStatus::class,
            'supply_chain_reviewed_at' => 'datetime',
            'onboarding_submitted_at' => 'datetime',
            'affiliate_commission_override_bp' => 'integer',
            'referred_at' => 'datetime',
        ];
    }

    protected function businessDomain(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => app(DomainNormalizer::class)->normalize($value));
    }

    public function contacts(): HasMany { return $this->hasMany(PublisherContact::class); }
    public function contracts(): HasMany { return $this->hasMany(PublisherContract::class); }
    public function paymentProfile(): HasOne { return $this->hasOne(PublisherPaymentProfile::class); }
    public function sites(): HasMany { return $this->hasMany(Site::class); }
    public function qualityProfiles(): HasMany { return $this->hasMany(PublisherQualityProfile::class); }
    public function application(): HasOne { return $this->hasOne(PublisherApplication::class); }
    public function qualityReviewRuns(): HasMany { return $this->hasMany(PublisherQualityReviewRun::class); }
    public function qualityDecisions(): HasMany { return $this->hasMany(PublisherQualityDecision::class); }
    public function sellerDeclarations(): HasMany { return $this->hasMany(SellerDeclaration::class); }
    public function supplyChainReviewer(): BelongsTo { return $this->belongsTo(User::class, 'supply_chain_reviewed_by'); }
    public function referrer(): BelongsTo { return $this->belongsTo(self::class, 'referred_by_publisher_id'); }
    public function referrals(): HasMany { return $this->hasMany(self::class, 'referred_by_publisher_id'); }
    public function affiliateCommissions(): HasMany { return $this->hasMany(PublisherAffiliateCommission::class, 'referrer_publisher_id'); }
    public function referredAffiliateCommissions(): HasMany { return $this->hasMany(PublisherAffiliateCommission::class, 'referred_publisher_id'); }

    public function applicableRevenueShare(): string
    {
        $contract = $this->contracts()->where('status', 'ACTIVE')->latest()->first();

        return (string) ($contract?->revenue_share_percent
            ?? number_format((int) config('reporting.default_publisher_share_bp', 7000) / 100, 2, '.', ''));
    }
}
