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
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date', 'status' => FinancialPeriodStatus::class,
            'closing_started_at' => 'datetime', 'closed_at' => 'datetime', 'totals' => 'array',
        ];
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
