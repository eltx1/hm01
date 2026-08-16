<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublisherApplicationDomainClaim extends Model
{
    use HasUlids;

    protected $fillable = [
        'publisher_application_id', 'normalized_domain', 'publisher_seller_declaration_id',
        'website_seller_declaration_id', 'claim_status', 'verification_status', 'claimed_at',
        'released_at', 'verification_requested_at', 'last_checked_at', 'verified_at',
        'final_ads_txt_url', 'verification_http_status', 'verification_content_type',
        'evidence_sha256', 'failure_code', 'verification_attempt_count',
    ];

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
