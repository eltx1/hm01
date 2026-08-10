<?php

namespace App\Models;

use App\Enums\FinancialPeriodStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialPeriod extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id', 'period_key', 'starts_on', 'ends_on', 'currency', 'status',
        'closing_started_at', 'closed_at', 'closed_by', 'snapshot_hash', 'totals', 'notes',
        'readiness_snapshot', 'close_override_reason', 'close_override_at',
        'close_override_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date', 'status' => FinancialPeriodStatus::class,
            'closing_started_at' => 'datetime', 'closed_at' => 'datetime', 'totals' => 'array',
            'readiness_snapshot' => 'array', 'close_override_at' => 'datetime',
        ];
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function overrideActor()
    {
        return $this->belongsTo(User::class, 'close_override_by');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(PublisherStatement::class);
    }

    public function isClosed(): bool
    {
        return $this->status === FinancialPeriodStatus::Closed;
    }
}
