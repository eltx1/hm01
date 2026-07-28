<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidGamTemplate extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'name', 'mode', 'advertiser_name',
        'order_name_prefix', 'targeting_keys', 'universal_creative_template',
        'line_item_type', 'line_item_priority', 'currency', 'status', 'version', 'settings',
    ];

    protected function casts(): array
    {
        return ['targeting_keys' => 'array', 'settings' => 'array'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class, 'gam_connection_id');
    }

    public function setupRuns(): HasMany
    {
        return $this->hasMany(PrebidSetupRun::class);
    }

    public function remoteObjects(): HasMany
    {
        return $this->hasMany(PrebidGamRemoteObject::class);
    }
}
