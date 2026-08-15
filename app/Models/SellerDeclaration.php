<?php

namespace App\Models;

use App\Enums\SellerDeclarationStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\SupplyChain\DomainNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerDeclaration extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'publisher_id', 'site_id', 'seller_id', 'seller_type', 'ads_txt_relationship', 'name', 'domain', 'is_confidential', 'status', 'review_status', 'last_verified_at', 'reviewed_at', 'reviewed_by', 'metadata'];

    protected static function booted(): void
    {
        static::creating(function (SellerDeclaration $declaration): void {
            $declaration->review_status ??= SupplyChainReviewStatus::ReviewRequired;
            $declaration->status ??= SellerDeclarationStatus::Disabled;
        });
    }

    protected function casts(): array
    {
        return [
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
