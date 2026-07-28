<?php

namespace App\Models;

use App\Enums\GamErrorCategory;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamError extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'gam_api_operation_id', 'gam_sync_run_id',
        'category', 'code', 'message', 'retryable', 'context', 'occurred_at', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => GamErrorCategory::class,
            'retryable' => 'boolean',
            'context' => 'array',
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class, 'gam_connection_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(GamApiOperation::class, 'gam_api_operation_id');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(GamSyncRun::class, 'gam_sync_run_id');
    }
}
