<?php

namespace App\Models;

use App\Enums\SupplyChainReviewStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\SupplyChain\DomainNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandAdsTxtRecord extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'demand_account_id', 'site_id', 'domain',
        'publisher_account_id', 'relationship', 'certification_authority_id',
        'record_hash', 'raw_record', 'status', 'review_status', 'source', 'last_verified_at',
        'reviewed_at', 'reviewed_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (DemandAdsTxtRecord $record): void {
            $record->review_status ??= SupplyChainReviewStatus::ReviewRequired;
        });
    }

    protected function casts(): array
    {
        return [
            'review_status' => SupplyChainReviewStatus::class,
            'last_verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected function domain(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => app(DomainNormalizer::class)->normalize($value));
    }

    public function account(): BelongsTo { return $this->belongsTo(DemandAccount::class, 'demand_account_id'); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
