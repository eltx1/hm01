<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandError extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'demand_account_id', 'demand_site_id',
        'demand_placement_id', 'category', 'code', 'message', 'retryable',
        'context', 'occurred_at', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'retryable' => 'boolean',
            'context' => 'array',
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo { return $this->belongsTo(DemandAccount::class, 'demand_account_id'); }
    public function demandSite(): BelongsTo { return $this->belongsTo(DemandSite::class); }
    public function demandPlacement(): BelongsTo { return $this->belongsTo(DemandPlacement::class); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
}
