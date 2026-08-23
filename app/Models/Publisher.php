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

class Publisher extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = ['organization_id', 'legal_name', 'display_name', 'business_domain', 'supply_chain_review_status', 'supply_chain_reviewed_at', 'supply_chain_reviewed_by', 'status', 'billing_email', 'logo_path', 'dashboard_title', 'primary_color', 'internal_notes', 'onboarding_step', 'onboarding_submitted_at'];

    protected $hidden = ['internal_notes'];

    protected static function booted(): void
    {
        static::creating(function (Publisher $publisher): void {
            $publisher->supply_chain_review_status ??= SupplyChainReviewStatus::ReviewRequired;
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

    public function applicableRevenueShare(): string
    {
        $contract = $this->contracts()->where('status', 'ACTIVE')->latest()->first();

        return (string) ($contract?->revenue_share_percent
            ?? number_format((int) config('reporting.default_publisher_share_bp', 7000) / 100, 2, '.', ''));
    }
}
