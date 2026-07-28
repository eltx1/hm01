<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlacementTargeting extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'placement_targeting';

    protected $fillable = [
        'organization_id', 'site_id', 'placement_id', 'scope', 'environment',
        'targeting_key', 'targeting_values', 'is_active',
    ];

    protected function casts(): array
    {
        return ['targeting_values' => 'array', 'is_active' => 'boolean'];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function placement(): BelongsTo { return $this->belongsTo(Placement::class); }
}
