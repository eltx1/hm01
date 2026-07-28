<?php

namespace App\Models;

use App\Enums\GamSyncStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GamSyncRun extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'initiated_by', 'kind', 'status', 'dry_run',
        'counters', 'metadata', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GamSyncStatus::class,
            'dry_run' => 'boolean',
            'counters' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class, 'gam_connection_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(GamApiOperation::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(GamSyncLog::class);
    }
}
