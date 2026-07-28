<?php

namespace App\Models;

use App\Enums\PrebidSetupStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidSetupRun extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'prebid_gam_template_id', 'site_id',
        'initiated_by', 'status', 'dry_run', 'confirmation_token_hash', 'confirmed_at',
        'plan', 'counters', 'cursor', 'started_at', 'completed_at',
    ];

    protected $hidden = ['confirmation_token_hash'];

    protected function casts(): array
    {
        return [
            'status' => PrebidSetupStatus::class,
            'dry_run' => 'boolean',
            'confirmed_at' => 'datetime',
            'plan' => 'array',
            'counters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class, 'gam_connection_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PrebidGamTemplate::class, 'prebid_gam_template_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function remoteObjects(): HasMany
    {
        return $this->hasMany(PrebidGamRemoteObject::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(PrebidError::class);
    }
}
