<?php

namespace App\Models;

use App\Enums\BidderSellersJsonStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidderAdsTxtRecord extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'bidder_account_id', 'site_id', 'advertising_system_domain',
        'publisher_account_id', 'relationship', 'certification_authority_id', 'raw_record',
        'record_hash', 'status', 'review_status', 'source', 'remote_verification_status',
        'remote_error_code', 'remote_verified_at', 'last_verified_at', 'reviewed_at',
        'reviewed_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'review_status' => SupplyChainReviewStatus::class,
            'remote_verification_status' => BidderSellersJsonStatus::class,
            'remote_verified_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BidderAccount::class, 'bidder_account_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
