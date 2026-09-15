<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PublisherAffiliateCommission extends Model
{
    use HasUlids;

    protected $fillable = [
        'referrer_publisher_id',
        'referred_publisher_id',
        'source_publisher_statement_id',
        'financial_period_id',
        'currency',
        'basis_minor',
        'commission_rate_bp',
        'commission_minor',
        'status',
        'metadata',
        'calculated_by',
    ];

    protected function casts(): array
    {
        return [
            'basis_minor' => 'integer',
            'commission_rate_bp' => 'integer',
            'commission_minor' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Publisher::class, 'referrer_publisher_id');
    }

    public function referredPublisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class, 'referred_publisher_id');
    }

    public function sourceStatement(): BelongsTo
    {
        return $this->belongsTo(PublisherStatement::class, 'source_publisher_statement_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }
}
