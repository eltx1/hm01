<?php

namespace App\Models;

use App\Enums\SupplyChainReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAdsTxtRecord extends Model
{
    use HasUlids;

    protected $fillable = [
        'advertising_system_domain', 'publisher_account_id', 'relationship',
        'certification_authority_id', 'raw_record', 'record_hash', 'status', 'review_status',
        'effective_from', 'effective_to', 'last_verified_at', 'remote_verification_status',
        'remote_error_code', 'reviewed_at', 'reviewed_by', 'created_by', 'updated_by',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'review_status' => SupplyChainReviewStatus::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'last_verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }

    public function isEffective(): bool
    {
        $now = now();
        return $this->status === 'ACTIVE'
            && $this->review_status === SupplyChainReviewStatus::Verified
            && (! $this->effective_from || $this->effective_from->lte($now))
            && (! $this->effective_to || $this->effective_to->gt($now));
    }
}
