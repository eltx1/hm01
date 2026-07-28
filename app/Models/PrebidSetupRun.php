<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidSetupRun extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'prebid_gam_template_id', 'initiated_by',
        'status', 'dry_run', 'confirmed', 'estimated_objects', 'counters', 'cursor',
        'plan', 'metadata', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean', 'confirmed' => 'boolean', 'estimated_objects' => 'integer',
            'counters' => 'array', 'cursor' => 'array', 'plan' => 'array', 'metadata' => 'array',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
    public function template(): BelongsTo { return $this->belongsTo(PrebidGamTemplate::class, 'prebid_gam_template_id'); }
    public function initiator(): BelongsTo { return $this->belongsTo(User::class, 'initiated_by'); }
    public function remoteObjects(): HasMany { return $this->hasMany(PrebidGamRemoteObject::class); }
    public function errors(): HasMany { return $this->hasMany(PrebidError::class); }
}
