<?php

namespace App\Models;

use App\Models\Builders\PublisherApplicationDomainClaimBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PublisherApplicationDomainClaim extends Model
{
    use HasUlids;

    protected $fillable = [
        'publisher_application_id', 'normalized_domain', 'claim_status', 'claimed_at', 'released_at',
        'publisher_seller_declaration_id', 'website_seller_declaration_id', 'verification_status', 'verification_requested_at',
        'last_checked_at', 'verified_at', 'final_ads_txt_url', 'verification_http_status',
        'verification_content_type', 'evidence_sha256', 'failure_code', 'verification_attempt_count',
    ];

    public function newEloquentBuilder($query): PublisherApplicationDomainClaimBuilder
    {
        return new PublisherApplicationDomainClaimBuilder($query);
    }

    protected static function booted(): void
    {
        static::updating(function (self $claim): void {
            if ($claim->isDirty('normalized_domain') && $claim->getRawOriginal('website_seller_declaration_id') !== null) {
                throw new LogicException('An application domain cannot be reassigned after its permanent HMS seller identity has been reserved.');
            }
            if ($claim->isDirty('website_seller_declaration_id') && $claim->getRawOriginal('website_seller_declaration_id') !== null) {
                throw new LogicException('A reserved HMS seller identity cannot be replaced or recycled.');
            }
            if ($claim->isDirty('publisher_seller_declaration_id') && $claim->getRawOriginal('publisher_seller_declaration_id') !== null) {
                throw new LogicException('The application HMP seller identity reference is immutable once reserved.');
            }
        });

        static::deleting(function (self $claim): bool {
            if ($claim->website_seller_declaration_id || $claim->publisher_seller_declaration_id || $claim->verification_requested_at) {
                return false;
            }

            return true;
        });
    }

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'released_at' => 'datetime',
            'verification_requested_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'verified_at' => 'datetime',
            'verification_attempt_count' => 'integer',
            'verification_http_status' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PublisherApplication::class, 'publisher_application_id');
    }

    public function publisherSeller(): BelongsTo
    {
        return $this->belongsTo(SellerDeclaration::class, 'publisher_seller_declaration_id');
    }

    public function websiteSeller(): BelongsTo
    {
        return $this->belongsTo(SellerDeclaration::class, 'website_seller_declaration_id');
    }
}
