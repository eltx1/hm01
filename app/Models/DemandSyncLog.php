<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandSyncLog extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'demand_account_id', 'demand_site_id',
        'demand_placement_id', 'level', 'action', 'dry_run',
        'idempotency_key', 'request_payload', 'response_payload',
        'message', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo { return $this->belongsTo(DemandAccount::class, 'demand_account_id'); }
    public function demandSite(): BelongsTo { return $this->belongsTo(DemandSite::class); }
    public function demandPlacement(): BelongsTo { return $this->belongsTo(DemandPlacement::class); }
}
