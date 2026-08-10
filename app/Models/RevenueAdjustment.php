<?php

namespace App\Models;

use App\Enums\AdjustmentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueAdjustment extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'financial_period_id', 'report_source_connection_id',
        'publisher_id', 'site_id', 'campaign_id', 'effective_on', 'type',
        'amount_minor', 'currency', 'status', 'reason', 'metadata', 'created_by',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
        'decision_reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'status' => AdjustmentStatus::class,
            'metadata' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ReportSourceConnection::class, 'report_source_connection_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
