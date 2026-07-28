<?php

namespace App\Models;

use App\Enums\GamOperationStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamApiOperation extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'gam_sync_run_id', 'operation', 'service', 'method',
        'idempotency_key', 'dry_run', 'status', 'request_payload', 'response_payload',
        'remote_request_id', 'attempts', 'error_category', 'error_code', 'error_message',
        'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'status' => GamOperationStatus::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class, 'gam_connection_id');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(GamSyncRun::class, 'gam_sync_run_id');
    }
}
